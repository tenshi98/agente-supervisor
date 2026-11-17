<?php

declare(strict_types=1);

namespace Agente\Notify;

use Agente\Decision\Doubt;
use Agente\Pipeline\AnswerService;

/**
 * Correo de una duda: elige la plantilla según el tipo y el nivel (plantillas/duda_*), con enlace firmado de un solo uso
 * y botones mailto: como alternativa. Todo texto que viene de correos externos lo escapa el motor de plantillas.
 */
final class QuestionMail
{
    /** Plantilla de cada duda. @param array<string,mixed> $q */
    public static function templateFor(array $q): string
    {
        return match ((string) $q['kind']) {
            Doubt::KIND_PLACE => (string) ($q['level'] ?? '') === Doubt::LEVEL_CONFIRM ? 'duda_confirmacion' : 'duda_abierta',
            Doubt::KIND_CONFLICT => 'duda_conflicto',
            Doubt::KIND_CARGO => 'duda_carga',
            default => 'duda_faltante',
        };
    }

    /**
     * @param array<string,mixed> $q fila de ndb_agent_questions
     * @param array<string,mixed>|null $msg correo que originó la duda (subject, sender, received)
     * @param array<string,mixed> $recipient fila de ndb_agent_recipients
     * @param string $replyTo casilla que lee las respuestas por correo
     * @param bool $canRemember false para textos genéricos: no se ofrece «recordar»
     */
    public static function build(Templates $t, array $q, ?array $msg, array $recipient, string $url, int $ttlHours, string $replyTo, bool $canRemember = true, int $alsoCount = 0): Email
    {
        $d = QuestionText::describe($q);
        $id = sprintf('D-%04d', (int) $q['question_id']);
        $flow = (string) ($q['flow'] ?? '');
        $label = QuestionText::label((string) ($q['field'] ?? ''), $flow);
        $cands = AnswerService::candidates($q);
        $template = self::templateFor($q);

        $vars = [
            'question_id' => $id, 'token' => (string) $q['token'], 'title' => $d['title'], 'url' => $url, 'ttl_hours' => $ttlHours,
            'reply_to' => $replyTo, 'mailto_subject' => rawurlencode("Re: [Duda {$id}·{$q['token']}] " . $d['title']),
            'source' => (string) ($q['source'] ?? ''), 'context' => (string) ($q['context'] ?? ''),
            'message_subject' => (string) ($msg['subject'] ?? ''), 'message_sender' => (string) ($msg['sender'] ?? ''), 'message_received' => (string) ($msg['received'] ?? ''),
            'also_count' => $alsoCount, 'text' => (string) ($q['text'] ?? ''), 'point_label' => $label, 'can_remember' => $canRemember,
            'identifier' => '', 'lead' => $d['lead'], 'field_label' => $label,
        ];

        if ($template === 'duda_confirmacion') {
            $pid = (string) ($q['proposal_place_id'] ?? '');
            $p = $cands[0] ?? ['nombre' => '', 'direccion' => ''];
            foreach ($cands as $c) {
                if ($pid !== '' && $c['place_id'] === $pid) {
                    $p = $c;
                }
            }
            $vars['proposal'] = ['name' => $p['nombre'], 'address' => $p['direccion']];
        } elseif ($template === 'duda_abierta') {
            $vars['candidates'] = array_map(
                static fn (array $c, int $i): array => ['n' => $i + 1, 'name' => $c['nombre'], 'address' => $c['direccion'], 'badge' => $i === 0 && $c['similitud'] > 0 ? 'más parecido' : ''],
                $cands, array_keys($cands)
            );
        } elseif ($template === 'duda_conflicto') {
            $origins = ['Decisión anterior', 'Regla actual'];
            $vars['options'] = array_map(
                static fn (array $c, int $i): array => ['n' => $i + 1, 'name' => $c['nombre'], 'origin' => $origins[$i] ?? ''],
                $cands, array_keys($cands)
            );
        } elseif ($template === 'duda_carga') {
            $vars['identifier'] = (string) ($q['text'] ?? '');
            $vars['reason'] = (string) ($q['context'] ?? '');
            $vars['items'] = [['label' => 'Carga', 'value' => (string) ($q['text'] ?? '')]];
        }

        $r = $t->render($template, $vars);

        return new Email((string) $recipient['email'], (string) ($recipient['name'] ?? ''), $r['subject'], $r['text'], $r['html']);
    }
}
