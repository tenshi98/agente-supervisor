<?php

declare(strict_types=1);

namespace Agente\Pipeline;

use Agente\Decision\DecisionEngine;
use Agente\Decision\DecisionResult;
use Agente\Llm\LlmException;
use Agente\Persistence\Actions;
use Agente\Persistence\Aliases;
use Agente\Persistence\Cases;
use Agente\Persistence\Messages;
use Agente\Persistence\Questions;
use Agente\Persistence\Recipients;
use Agente\Persistence\Usage;
use Agente\Resolve\PlaceResolver;
use Agente\Resolve\Resolution;
use Agente\Rules\RuleSet;
use Agente\Support\Logger;
use Agente\Text\Places;
use Agente\Understanding\Extraction;
use Agente\Understanding\Extractor;
use Agente\Understanding\Prefilter;
use PDO;
use Throwable;

/**
 * Pasos 4 y 5 sobre los correos ingeridos (status 'listo'):
 *   - Una respuesta a una duda («[Duda D-0001·token]») se aplica y no se procesa como instrucción.
 *   - Sin lectura previa: prefiltro sin IA -> IA comprende (texto primero, original solo si hace falta) -> se guarda.
 *   - Con lectura previa (p. ej. tras responder una duda): se reutiliza lo entendido, sin gastar tokens.
 *   - Se resuelven TODOS los lugares de la corrida juntos (PlaceResolver: 1 llamada al catálogo, 1 a la IA) y se decide.
 *   - Cada decisión produce las acciones de reglas/acciones.json. Lo que no se puede decidir crea dudas y el correo queda 'pendiente'.
 *   - Si el sistema externo no responde, el correo se deja en 'listo' y se reintenta en la próxima corrida (no se pregunta).
 */
final class Processor
{
    public function __construct(
        private readonly PDO $db,
        private readonly Messages $messages,
        private readonly Cases $cases,
        private readonly Questions $questions,
        private readonly Aliases $aliases,
        private readonly Usage $usage,
        private readonly DecisionEngine $engine,
        private readonly PlaceResolver $resolver,
        private readonly RuleSet $rules,
        private readonly Logger $log,
        private readonly ?Extractor $extractor,
        private readonly ?Actions $actions = null,
        private readonly ?AnswerService $answerService = null,
        private readonly ?Recipients $recipients = null,
    ) {
    }

    /**
     * @param array<string,mixed> $cfg fila de ndb_agent_config
     * @return array<string,int>
     */
    public function run(int $tenantId, array $cfg, int $batch = 20): array
    {
        $stats = ['leidos_ia' => 0, 'reutilizados' => 0, 'decididos' => 0, 'pendientes' => 0, 'irrelevantes' => 0, 'sin_senal' => 0, 'errores' => 0,
            'sin_ia' => 0, 'tope_ia' => 0, 'respuestas_por_correo' => 0, 'diferidos' => 0];
        $maxUsd = (float) ($cfg['ai_max_usd_day'] ?? 5.0);
        $overBudget = false;
        $warnedNoAi = false;

        // Fase 1: respuestas por correo, prefiltro y comprensión (IA). Los correos listos para decidir se acumulan.
        /** @var array<int,array{msg:array<string,mixed>,ex:Extraction}> $ready */
        $ready = [];
        foreach ($this->messages->ready($tenantId, $batch) as $msg) {
            $mid = (int) $msg['message_id'];
            try {
                if ($this->handleMailAnswer($tenantId, $msg)) {
                    $stats['respuestas_por_correo']++;
                    continue;
                }
                $atts = $this->messages->attachments($mid);
                $ex = null;
                if (!empty($msg['extract_json'])) {
                    $data = json_decode((string) $msg['extract_json'], true);
                    $ex = is_array($data) ? Extraction::fromArray($data) : null;
                    if ($ex !== null) {
                        $stats['reutilizados']++;
                    }
                }
                if ($ex === null) {
                    if (!Prefilter::hasSignal((string) $msg['subject'], (string) $msg['body_text'], $atts, $this->rules)) {
                        $this->messages->finish($mid, 'descartado', null, 'sin_senal');
                        $stats['sin_senal']++;
                        continue;
                    }
                    if ($this->extractor === null) {
                        if (!$warnedNoAi) {
                            $this->log->warn('Falta ANTHROPIC_API_KEY: los correos quedan en cola sin leer con IA.');
                            $warnedNoAi = true;
                        }
                        $stats['sin_ia']++;
                        continue;
                    }
                    if ($overBudget || $this->usage->costToday($tenantId) >= $maxUsd) {
                        $overBudget = true;
                        $stats['tope_ia']++;
                        continue;
                    }
                    $res = $this->extractor->extract($msg, $atts);
                    foreach ($res->calls as $call) {
                        $this->usage->record($tenantId, $call['purpose'], $call['response'], $mid);
                    }
                    $ex = $res->extraction;
                    $this->messages->saveExtraction($mid, (string) json_encode($ex->toArray(), JSON_UNESCAPED_UNICODE), $ex->tipo, $ex->flujo);
                    $stats['leidos_ia']++;
                }
                $ready[$mid] = ['msg' => $msg, 'ex' => $ex];
            } catch (Throwable $e) {
                $this->fail($mid, $e, $stats);
            }
        }
        if ($ready === []) {
            return $stats;
        }

        // Fase 2: resolver todos los lugares de la corrida juntos (una llamada al catálogo, una a la IA).
        $mentions = [];
        foreach ($ready as $r) {
            if (!$r['ex']->relevante || !in_array($r['ex']->tipo, $this->rules->processedTypes(), true)) {
                continue;
            }
            foreach ($r['ex']->cargas as $c) {
                foreach (Extraction::ROLES as $role) {
                    foreach ($c['lugares'][$role] as $m) {
                        $mentions[] = ['texto' => $m['texto'], 'contexto' => $m['contexto'], 'rol' => $role, 'flujo' => $r['ex']->flujo];
                    }
                }
            }
        }
        try {
            $resolutions = $this->resolver->resolve($tenantId, $mentions, $this->aliases->places($tenantId), 'ingest');
        } catch (Throwable $e) {
            $this->log->error('Resolución de lugares: ' . $e::class . ': ' . $e->getMessage());
            $stats['diferidos'] += count($ready);

            return $stats; // todo queda 'listo' y se reintenta
        }

        // Fase 3: decidir y ejecutar las acciones.
        foreach ($ready as $mid => $r) {
            try {
                if ($this->deferred($r['ex'], $resolutions)) {
                    $stats['diferidos']++;
                    continue; // el sistema externo no respondió: no se decide ni se pregunta
                }
                $answers = $this->questions->answersForMessage($tenantId, $mid);
                $result = $this->engine->decide($r['ex'], $mid, $resolutions, $answers);
                $this->persist($tenantId, $r['msg'], $result, $stats);
            } catch (Throwable $e) {
                $this->fail($mid, $e, $stats);
            }
        }

        return $stats;
    }

    /** @param array<string,Resolution> $resolutions */
    private function deferred(Extraction $ex, array $resolutions): bool
    {
        foreach ($ex->cargas as $c) {
            foreach (Extraction::ROLES as $role) {
                foreach ($c['lugares'][$role] as $m) {
                    if (($resolutions[Resolution::key($role, $m['texto'])] ?? null)?->level === Resolution::DEFER) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @param array<string,int> $stats */
    private function fail(int $mid, Throwable $e, array &$stats): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        $stats['errores']++;
        $this->log->error("Correo {$mid}: " . $e::class . ': ' . $e->getMessage());
        // Un rechazo o respuesta cortada de la IA no se arregla reintentando: se corta de inmediato (no gasta más tokens).
        $final = $e instanceof LlmException && !$e->retryable;
        $this->messages->fail($mid, $e::class . ': ' . $e->getMessage(), $final ? 1 : 3);
    }

    /**
     * Si el correo es la respuesta a una duda («[Duda D-0012·ab12cd]» en el asunto) la aplica y lo marca como
     * `respuesta_duda`. Solo responde un destinatario con rol «duda» y con el código correcto de la duda.
     *
     * @param array<string,mixed> $msg
     */
    private function handleMailAnswer(int $tenantId, array $msg): bool
    {
        if (preg_match('/\[Duda D-(\d+)\x{00B7}([0-9a-f]{6})\]/iu', (string) $msg['subject'], $m) !== 1) {
            return false;
        }
        $mid = (int) $msg['message_id'];
        $this->messages->markAsAnswer($mid);
        if ($this->answerService === null || $this->recipients === null) {
            $this->log->warn("Correo {$mid} responde a una duda pero la respuesta por correo no está habilitada.");

            return true;
        }
        $sender = (string) $msg['sender'];
        $email = preg_match('/<([^>]+)>/', $sender, $e) === 1 ? $e[1] : $sender;
        $decider = $this->recipients->deciderByEmail($tenantId, $email);
        $q = $this->questions->byToken($tenantId, (int) $m[1], strtolower($m[2]));
        if ($decider === null || $q === null) {
            $this->log->warn("Respuesta por correo ignorada (remitente no autorizado o duda inexistente): {$email}");

            return true;
        }
        $answer = AnswerService::parseMail($q, (string) $msg['body_text']);
        if (is_string($answer)) {
            $this->log->warn("Respuesta por correo no entendida en la duda {$m[1]}: {$answer}");

            return true;
        }
        $this->answerService->apply($q, (int) $decider['recipient_id'], $answer, 'correo');

        return true;
    }

    /** @param array<string,mixed> $msg @param array<string,int> $stats */
    private function persist(int $tenantId, array $msg, DecisionResult $result, array &$stats): void
    {
        $mid = (int) $msg['message_id'];
        $used = [];
        $this->db->beginTransaction();
        foreach ($result->items as $item) {
            $caseId = $this->cases->upsert($tenantId, $msg, $item);
            foreach ($item->doubts as $doubt) {
                $this->questions->link($this->questions->openOrCreate($tenantId, $doubt, $mid), $caseId);
            }
            // Acciones de cada decisión (reglas/acciones.json), idempotentes por caso.
            foreach ($item->decisions() as $decision) {
                foreach ($this->rules->actionsOf($decision) as $action) {
                    $this->actions?->once($tenantId, $decision, $action, 'c' . $caseId);
                }
            }
            foreach (($item->data['lugares'] ?? []) as $list) {
                foreach ($list as $p) {
                    if (($p['metodo'] ?? '') === 'equivalencia') {
                        $used[] = Places::normalize((string) $p['texto']);
                    }
                }
            }
        }
        if ($result->items === []) {
            foreach ($result->decisions() as $decision) {
                foreach ($this->rules->actionsOf($decision) as $action) {
                    $this->actions?->once($tenantId, $decision, $action, 'm' . $mid);
                }
            }
        }
        $summary = [
            'estado' => $result->status,
            'motivo' => $result->reason,
            'cargas' => array_map(static fn ($i): array => ['clave' => $i->key, 'estado' => $i->state(), 'decisiones' => $i->decisions(), 'datos' => $i->data], $result->items),
        ];
        $status = $result->status === DecisionResult::PENDING ? 'pendiente' : 'procesado';
        $this->messages->finish($mid, $status, (string) json_encode($summary, JSON_UNESCAPED_UNICODE));
        $this->db->commit();

        $this->aliases->bumpUses($tenantId, $used);
        match ($result->status) {
            DecisionResult::PENDING => $stats['pendientes']++,
            DecisionResult::IRRELEVANT => $stats['irrelevantes']++,
            default => $stats['decididos']++,
        };
    }
}
