<?php

declare(strict_types=1);

namespace Agente\Notify;

use Agente\Persistence\Alerts;
use Agente\Persistence\Outbox;
use Agente\Persistence\Recipients;
use Agente\Support\LinkSigner;
use Agente\Support\Logger;
use PDO;

/**
 * Cron `agent_summary` (sin IA): resumen diario a los destinatarios con rol `resumen`. Lo llama el cron a la hora
 * configurada (ndb_agent_config.summary_hour, hora local del tenant; 19:00 por defecto). No repite el mismo día.
 * Incluye las diferencias de tramos ya ejecutados (informativas), las dudas pendientes, el costo de IA y las consultas MCP.
 */
final class SummaryNotifier
{
    public function __construct(
        private readonly PDO $db,
        private readonly Alerts $alerts,
        private readonly Recipients $recipients,
        private readonly Outbox $outbox,
        private readonly Mailer $mailer,
        private readonly Templates $templates,
        private readonly LinkSigner $signer,
        private readonly Logger $log,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * @param array<string,mixed> $cfg fila de ndb_agent_config (+ 'timezone' del tenant)
     * @param bool $force ignora la hora configurada (uso manual)
     * @return array{enviados:int,omitidos:int,errores:int,fuera_de_hora:bool}
     */
    public function send(int $tenantId, array $cfg, bool $force = false, ?int $now = null): array
    {
        $stats = ['enviados' => 0, 'omitidos' => 0, 'errores' => 0, 'fuera_de_hora' => false];
        $tz = new \DateTimeZone((string) ($cfg['timezone'] ?? 'America/Santiago'));
        $local = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz);
        if (!$force && (int) $local->format('G') < (int) ($cfg['summary_hour'] ?? 19)) {
            $stats['fuera_de_hora'] = true;

            return $stats;
        }
        $to = $this->recipients->byRole($tenantId, 'resumen');
        if ($to === []) {
            $this->log->warn('Resumen diario sin destinatarios con rol "resumen" activos.');

            return $stats;
        }
        $since = $local->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $base = $this->data($tenantId, $since, $local);
        $real = $this->mailer->isReal();
        $ttl = max(1, (int) ($cfg['link_ttl_hours'] ?? 72));

        foreach ($to as $r) {
            if ($this->outbox->sentTodayTo($tenantId, 'resumen', (string) $r['email'])) {
                $stats['omitidos']++;
                continue;
            }
            $vars = $base;
            foreach ($vars['pending'] as $i => $p) {
                $vars['pending'][$i]['url'] = rtrim($this->baseUrl, '/') . '/?t=' . rawurlencode($this->signer->issue((int) $p['qid'], $tenantId, (int) $r['recipient_id'], $ttl * 3600));
            }
            $mail = $this->templates->render('resumen_diario', $vars);
            try {
                $path = $this->mailer->send(new Email((string) $r['email'], (string) ($r['name'] ?? ''), $mail['subject'], $mail['text'], $mail['html']));
                $this->outbox->record($tenantId, null, (int) $r['recipient_id'], (string) $r['email'], $mail['subject'], $real ? 'enviado' : 'simulado', null, $path, 'resumen');
                $stats['enviados']++;
            } catch (\Throwable $e) {
                $stats['errores']++;
                $this->outbox->record($tenantId, null, (int) $r['recipient_id'], (string) $r['email'], $mail['subject'], 'error', $e->getMessage(), null, 'resumen');
                $this->log->error("Resumen a {$r['email']}: " . $e->getMessage());
            }
        }

        return $stats;
    }

    /** @return array<string,mixed> variables de plantillas/resumen_diario (sin los enlaces de las dudas, que dependen del destinatario) */
    private function data(int $tenantId, string $since, \DateTimeImmutable $local): array
    {
        $one = function (string $sql, array $args): array {
            $st = $this->db->prepare($sql);
            $st->execute($args);

            return $st->fetch(PDO::FETCH_NUM) ?: [];
        };
        $m = $one("SELECT COUNT(*), COALESCE(SUM(tipo = 'instruccion_transporte'),0) FROM ndb_agent_messages WHERE tenant_id = ? AND created >= ?", [$tenantId, $since]);
        $c = $one("SELECT COUNT(*), COALESCE(SUM(state IN ('decidido','cruzado')),0) FROM ndb_agent_cases WHERE tenant_id = ? AND received >= ?", [$tenantId, $since]);
        $q = $one("SELECT COALESCE(SUM(sent >= ?),0), COALESCE(SUM(status IN ('nueva','enviada')),0) FROM ndb_agent_questions WHERE tenant_id = ?", [$since, $tenantId]);
        $al = $one("SELECT COUNT(*) FROM ndb_agent_aliases WHERE tenant_id = ? AND source = 'usuario' AND created >= ?", [$tenantId, $since]);
        $ai = $one('SELECT COALESCE(SUM(cost_usd),0), COUNT(*), COALESCE(SUM(tokens_in),0), COALESCE(SUM(tokens_out),0) FROM ndb_agent_usage WHERE tenant_id = ? AND created >= ?', [$tenantId, $since]);
        $mc = $one('SELECT COUNT(*), COALESCE(SUM(items),0) FROM ndb_agent_mcp_log WHERE tenant_id = ? AND created >= ?', [$tenantId, $since]);
        $counts = $this->alerts->counts($tenantId, $since);

        $informatives = [];
        foreach ($this->alerts->forDailySummary($tenantId, $since) as $a) {
            $informatives[] = [
                'identifier' => (string) ($a['container'] ?: ($a['booking'] ?: $a['shipment_ref'])), 'order_id' => (string) ($a['order_id'] ?? ''),
                'point_label' => $a['point'] !== null ? QuestionText::label((string) $a['point'], (string) ($a['flow'] ?? '')) : 'Carga',
                'mail_value' => (string) $a['mail_value'], 'system_value' => (string) $a['system_value'], 'source' => (string) ($a['source'] ?? '') ?: 'correo',
                'note' => 'El camión ya ejecutó el tramo: el plan cambió después del correo.',
            ];
        }

        $st = $this->db->prepare("SELECT * FROM ndb_agent_questions WHERE tenant_id = ? AND status IN ('nueva','enviada') ORDER BY question_id LIMIT 50");
        $st->execute([$tenantId]);
        $pending = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($st->fetchAll() as $row) {
            $age = max(0, (int) round(($now->getTimestamp() - (int) strtotime((string) $row['created'] . ' UTC')) / 3600));
            $pending[] = ['qid' => (int) $row['question_id'], 'id' => sprintf('D-%04d', (int) $row['question_id']), 'title' => QuestionText::describe($row)['title'],
                'age' => $age < 48 ? "{$age} h" : intdiv($age, 24) . ' días', 'url' => ''];
        }

        return [
            'date' => $local->format('d-m-Y'),
            'totals' => [
                'mails' => (int) ($m[0] ?? 0), 'instructions' => (int) ($m[1] ?? 0), 'cases' => (int) ($c[0] ?? 0), 'automatic' => (int) ($c[1] ?? 0),
                'questions_sent' => (int) ($q[0] ?? 0), 'questions_pending' => (int) ($q[1] ?? 0), 'alerts_sent' => $counts['enviados'],
                'revalidated' => $counts['revalidados'], 'aliases_new' => (int) ($al[0] ?? 0),
            ],
            'informatives' => $informatives,
            'pending' => $pending,
            'ai' => ['cost_usd' => number_format((float) ($ai[0] ?? 0), 4, '.', ''), 'calls' => (int) ($ai[1] ?? 0), 'tokens_in' => (int) ($ai[2] ?? 0), 'tokens_out' => (int) ($ai[3] ?? 0)],
            'mcp' => ['calls' => (int) ($mc[0] ?? 0), 'items' => (int) ($mc[1] ?? 0)],
        ];
    }
}
