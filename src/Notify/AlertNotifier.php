<?php

declare(strict_types=1);

namespace Agente\Notify;

use Agente\Check\CaseEvaluator;
use Agente\Check\PlaceComparer;
use Agente\Mcp\OrdersClient;
use Agente\Persistence\Alerts;
use Agente\Persistence\Outbox;
use Agente\Persistence\Recipients;
use Agente\Support\Logger;
use Agente\Support\SignedToken;

/**
 * Cron `agent_notify` (sin IA): resumen por hora de avisos. Justo antes de enviar REVALIDA todos los avisos pendientes
 * con UNA llamada al sistema externo: si operaciones ya cargó el dato, el aviso se descarta en silencio.
 * Los destinatarios salen solo de ndb_agent_recipients (rol `aviso`).
 *
 * Feedback ✓/✗: cada botón lleva un token `SignedToken($secret, 'alert-feedback')` con claims
 * {a: alert_id, t: tenant_id, r: recipient_id, v: 1|0}. La página public/aviso.php lo verifica con el mismo propósito,
 * comprueba que r sea un destinatario activo del tenant y llama Alerts::setFeedback(a, v, r).
 */
final class AlertNotifier
{
    public const FEEDBACK_PURPOSE = 'alert-feedback';
    private const BADGES = [
        'falta_en_sistema' => ['Sin orden', '#b91c1c'],
        'lugar_vacio' => ['Falta cargar', '#b45309'],
        'distinto' => ['No coincide', '#1d3c8b'],
    ];

    public function __construct(
        private readonly Alerts $alerts,
        private readonly Recipients $recipients,
        private readonly Outbox $outbox,
        private readonly Mailer $mailer,
        private readonly OrdersClient $orders,
        private readonly Templates $templates,
        private readonly SignedToken $feedbackSigner,
        private readonly Logger $log,
        private readonly string $baseUrl,
        private readonly int $feedbackTtlHours = 24 * 14,
    ) {
    }

    /**
     * @param array<string,mixed> $cfg fila de ndb_agent_config (+ opcional 'timezone' del tenant)
     * @return array{pendientes:int,revalidados:int,diferidos:int,enviados:int,errores:int}
     */
    public function send(int $tenantId, array $cfg, string $process = 'notify', ?int $now = null): array
    {
        $stats = ['pendientes' => 0, 'revalidados' => 0, 'diferidos' => 0, 'enviados' => 0, 'errores' => 0];
        $pending = $this->alerts->pendingForNotify($tenantId);
        $stats['pendientes'] = count($pending);
        if ($pending === []) {
            return $stats;
        }
        $to = $this->recipients->byRole($tenantId, 'aviso');
        if ($to === []) {
            $this->log->warn('Hay avisos por enviar pero no hay destinatarios con rol "aviso" activos.');

            return $stats;
        }

        // Revalidar: una sola llamada con todos los avisos.
        $items = [];
        foreach ($pending as $a) {
            $items[] = [
                'ref' => 'a' . $a['alert_id'], 'contenedor' => (string) ($a['container'] ?? ''), 'despacho' => (string) ($a['shipment_ref'] ?? ''),
                'booking' => (string) ($a['booking'] ?? ''), 'fecha_correo' => substr((string) ($a['case_received'] ?? ''), 0, 10),
                'puntos' => $a['point'] !== null ? [(string) $a['point'] => (string) $a['mail_value']] : [],
            ];
        }
        $res = $this->orders->revalidate($tenantId, $items, $process);

        $keep = [];
        foreach ($pending as $a) {
            $v = self::verdict($a, $res['a' . $a['alert_id']] ?? null);
            if ($v === 'discard') {
                $this->alerts->markRevalidated((int) $a['alert_id']);
                $stats['revalidados']++;
            } elseif ($v === 'defer') {
                $stats['diferidos']++; // el sistema no respondió: se reintenta en la próxima hora
            } else {
                $keep[] = $a;
            }
        }
        if ($keep === []) {
            return $stats; // nada que avisar: no se envía ningún correo
        }

        $real = $this->mailer->isReal();
        $tz = new \DateTimeZone((string) ($cfg['timezone'] ?? 'America/Santiago'));
        $local = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz);
        $counts = ['falta_en_sistema' => 0, 'lugar_vacio' => 0, 'distinto' => 0];
        foreach ($keep as $a) {
            $counts[(string) $a['kind']] = ($counts[(string) $a['kind']] ?? 0) + 1;
        }
        $firstOutbox = null;
        $delivered = false;
        foreach ($to as $r) {
            $cards = [];
            foreach ($keep as $a) {
                $cards[] = self::card($a, $this->feedbackUrl((int) $a['alert_id'], $tenantId, (int) $r['recipient_id'], 1), $this->feedbackUrl((int) $a['alert_id'], $tenantId, (int) $r['recipient_id'], 0));
            }
            $mail = $this->templates->render('aviso_hora', ['date' => $local->format('d-m-Y'), 'hour' => $local->format('H:i'), 'total' => count($keep), 'counts' => $counts, 'alerts' => $cards]);
            try {
                $path = $this->mailer->send(new Email((string) $r['email'], (string) ($r['name'] ?? ''), $mail['subject'], $mail['text'], $mail['html']));
                $id = $this->outbox->record($tenantId, null, (int) $r['recipient_id'], (string) $r['email'], $mail['subject'], $real ? 'enviado' : 'simulado', null, $path, 'aviso');
                $firstOutbox ??= $id;
                $delivered = true;
            } catch (\Throwable $e) {
                $stats['errores']++;
                $this->outbox->record($tenantId, null, (int) $r['recipient_id'], (string) $r['email'], $mail['subject'], 'error', $e->getMessage(), null, 'aviso');
                $this->log->error("Aviso a {$r['email']}: " . $e->getMessage());
            }
        }
        if ($delivered) {
            foreach ($keep as $a) {
                $this->alerts->markSent((int) $a['alert_id'], $firstOutbox);
                $stats['enviados']++;
            }
        }

        return $stats;
    }

    /**
     * Decide qué hacer con un aviso tras consultar de nuevo el sistema.
     *  - 'discard': el dato ya está cargado (o coincide) → se descarta en silencio.
     *  - 'defer': el sistema no respondió → se deja pendiente.
     *  - 'send': el problema sigue → se avisa.
     *
     * @param array<string,mixed> $alert fila de ndb_agent_alerts
     * @param array{status:string,orden:?array,unidad:?array,tramos:list<array>}|null $res resultado de revalidar_avisos
     */
    public static function verdict(array $alert, ?array $res): string
    {
        if ($res === null || $res['status'] === 'error') {
            return 'defer';
        }
        $found = $res['status'] === 'ok' && $res['orden'] !== null;
        $kind = (string) $alert['kind'];
        if ($kind === 'falta_en_sistema') {
            return $found ? 'discard' : 'send';
        }
        if (!$found) {
            return 'send';
        }
        $point = (string) ($alert['point'] ?? '');
        $sys = trim((string) (($res['unidad'] ?? [])[CaseEvaluator::SYSTEM_FIELD[$point] ?? ''] ?? ''));
        if ($kind === 'lugar_vacio') {
            return $sys !== '' ? 'discard' : 'send';
        }
        // distinto: ya coincide con el correo (o el lugar está en algún tramo) → no hay nada que avisar
        $mail = (string) $alert['mail_value'];
        if ($sys !== '' && PlaceComparer::same($mail, $sys)) {
            return 'discard';
        }
        foreach ($res['tramos'] ?? [] as $t) {
            if (PlaceComparer::same($mail, (string) ($t['to_name'] ?? '')) || PlaceComparer::same($mail, (string) ($t['from_name'] ?? ''))) {
                return 'discard';
            }
        }

        return 'send';
    }

    /**
     * Variables de un aviso para plantillas/aviso_hora: qué pasó, de dónde sale el dato y qué hacer, con vocabulario de operaciones.
     *
     * @param array<string,mixed> $a fila de pendingForNotify
     * @return array<string,string>
     */
    public static function card(array $a, string $okUrl, string $noUrl): array
    {
        $kind = (string) $a['kind'];
        $label = $a['point'] !== null ? mb_strtolower(QuestionText::label((string) $a['point'], (string) ($a['flow'] ?? ''))) : '';
        $order = ($a['order_id'] ?? '') !== '' ? 'orden ' . $a['order_id'] : 'orden';
        $mail = (string) $a['mail_value'];
        $sys = (string) $a['system_value'];
        [$what, $todo] = match ($kind) {
            'falta_en_sistema' => [
                'Llegó una instrucción para esta carga y, pasada la hora de gracia, no existe la orden en el sistema.',
                'Crear la orden con los datos del correo.',
            ],
            'lugar_vacio' => [
                "El correo indica {$label} en {$mail}, pero la {$order} no lo tiene cargado.",
                "Cargar {$label}: {$mail} en la {$order}.",
            ],
            default => [
                "El correo indica {$label} en {$mail}, pero la {$order} tiene {$sys}.",
                "Revisar la {$order}. Si el plan cambió después del correo, no hay que hacer nada.",
            ],
        };
        $src = (string) ($a['source'] ?? '');
        $from = match ($src) {
            'asunto' => 'asunto del correo', 'cuerpo' => 'cuerpo del correo', '' => 'correo', default => 'adjunto ' . $src,
        };
        if (($a['mail_subject'] ?? '') !== '') {
            $from .= ' «' . mb_substr((string) $a['mail_subject'], 0, 80) . '»';
        }
        [$badge, $color] = self::BADGES[$kind] ?? [$kind, '#1d3c8b'];
        $id = (string) ($a['container'] ?? '') ?: ((string) ($a['booking'] ?? '') ?: (string) ($a['shipment_ref'] ?? ''));

        return [
            'badge' => $badge, 'badge_bg' => $color, 'identifier' => $id, 'order_id' => (string) ($a['order_id'] ?? ''),
            'what_happened' => $what, 'point_label' => $label !== '' ? ucfirst($label) : 'Carga', 'mail_value' => $kind === 'falta_en_sistema' ? '' : $mail,
            'system_value' => $kind === 'falta_en_sistema' ? '' : $sys, 'source' => $from, 'what_to_do' => $todo,
            'feedback_ok_url' => $okUrl, 'feedback_no_url' => $noUrl,
        ];
    }

    private function feedbackUrl(int $alertId, int $tenantId, int $recipientId, int $value): string
    {
        $t = $this->feedbackSigner->issue(['a' => $alertId, 't' => $tenantId, 'r' => $recipientId, 'v' => $value], $this->feedbackTtlHours * 3600);

        return rtrim($this->baseUrl, '/') . '/aviso.php?t=' . rawurlencode($t);
    }
}
