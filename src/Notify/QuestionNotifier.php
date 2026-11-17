<?php

declare(strict_types=1);

namespace Agente\Notify;

use Agente\Persistence\Outbox;
use Agente\Persistence\Questions;
use Agente\Persistence\Recipients;
use Agente\Rules\RuleSet;
use Agente\Text\Places;
use Agente\Support\LinkSigner;
use Agente\Support\Logger;

/**
 * Paso 6: envía cada duda nueva a los destinatarios con rol 'duda' (ndb_agent_recipients) con un enlace firmado de un solo
 * uso (vía principal) y botones mailto: (alternativa). Destinatarios SOLO de la base de datos. Respeta el tope por corrida
 * y por día; lo que excede el tope queda 'nueva' y sale en la ventana siguiente.
 */
final class QuestionNotifier
{
    public function __construct(
        private readonly Questions $questions,
        private readonly Recipients $recipients,
        private readonly Outbox $outbox,
        private readonly Mailer $mailer,
        private readonly LinkSigner $signer,
        private readonly Logger $log,
        private readonly string $baseUrl,
        private readonly Templates $templates,
        private readonly string $replyTo,
        private readonly ?RuleSet $rules = null,
    ) {
    }

    /** @return array{sent:int,previews:int,errors:int} */
    public function sendPending(int $tenantId, int $maxPerRun, int $maxPerDay, int $ttlHours): array
    {
        $stats = ['sent' => 0, 'previews' => 0, 'errors' => 0];
        $deciders = $this->recipients->deciders($tenantId);
        $real = $this->mailer->isReal();
        $budget = $real ? min($maxPerRun, $maxPerDay - $this->questions->sentToday($tenantId)) : $maxPerRun;
        if ($budget <= 0) {
            return $stats;
        }
        // En prueba las dudas no cambian de estado: se miran más para no quedarse siempre con las primeras.
        $pending = $this->questions->toSend($tenantId, $real ? $budget : 200);
        if ($pending !== [] && $deciders === []) {
            $this->log->warn('Hay dudas por enviar pero no hay destinatarios con rol "duda" activos (ndb_agent_recipients).');

            return $stats;
        }
        foreach ($pending as $q) {
            if (!$real && $stats['previews'] >= $maxPerRun) {
                break;
            }
            $qid = (int) $q['question_id'];
            $msg = $this->questions->sourceMessage($q['message_id'] === null ? null : (int) $q['message_id']);
            $delivered = false;
            foreach ($deciders as $r) {
                $rid = (int) $r['recipient_id'];
                if (!$real && $this->outbox->hasSimulated($qid, $rid)) {
                    continue; // vista previa ya generada
                }
                $url = rtrim($this->baseUrl, '/') . '/?t=' . rawurlencode($this->signer->issue($qid, $tenantId, $rid, $ttlHours * 3600));
                $mail = QuestionMail::build($this->templates, $q, $msg, $r, $url, $ttlHours, $this->replyTo, $this->canRemember($q));
                try {
                    $path = $this->mailer->send($mail);
                    $this->outbox->record($tenantId, $qid, $rid, $mail->to, $mail->subject, $real ? 'enviado' : 'simulado', null, $path);
                    $delivered = true;
                    $real ? $stats['sent']++ : $stats['previews']++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $this->outbox->record($tenantId, $qid, $rid, $mail->to, $mail->subject, 'error', $e->getMessage(), null);
                    $this->log->error("Duda D-{$qid} a {$mail->to}: " . $e->getMessage());
                }
            }
            if ($real && $delivered) {
                $this->questions->markSent($qid);
            }
        }

        return $stats;
    }

    /** «Recordar» solo se ofrece para lugares con texto no genérico. @param array<string,mixed> $q */
    private function canRemember(array $q): bool
    {
        if (!in_array((string) $q['kind'], ['lugar', 'conflicto'], true)) {
            return false;
        }
        if ($this->rules === null) {
            return true;
        }
        $norm = Places::normalize((string) ($q['text'] ?? ''));
        foreach ($this->rules->genericPlaces() as $g) {
            if (Places::normalize($g) === $norm) {
                return false;
            }
        }

        return true;
    }
}
