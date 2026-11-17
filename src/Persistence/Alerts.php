<?php

declare(strict_types=1);

namespace Agente\Persistence;

use PDO;

/**
 * Avisos del cruce (ndb_agent_alerts). Salen en el resumen por hora (revalidados antes de enviar) o solo en el
 * resumen diario (tramo ya ejecutado). `sig` hace cada aviso idempotente.
 */
final class Alerts
{
    public const PENDING = 'pendiente';
    public const SENT = 'enviado';
    public const DAILY = 'resumen_diario';
    public const REVALIDATED = 'descartado_revalidado';

    public function __construct(private readonly PDO $db)
    {
    }

    public static function signature(int $tenantId, int $caseId, string $kind, ?string $point, string $mailValue, string $systemValue): string
    {
        return sha1(implode('|', [$tenantId, $caseId, $kind, (string) $point, $mailValue, $systemValue]));
    }

    /**
     * Crea un aviso si no existe uno igual. Un aviso `executed` nace en 'resumen_diario' (nunca va al resumen por hora).
     *
     * @param array{kind:string,point:?string,mail_value:string,system_value:string,source?:string,executed?:bool,flow?:?string,order_id?:?string} $a
     * @return int|null id del aviso nuevo; null si ya existía
     */
    public function create(int $tenantId, int $caseId, array $a): ?int
    {
        $executed = !empty($a['executed']);
        $sig = self::signature($tenantId, $caseId, $a['kind'], $a['point'], $a['mail_value'], $a['system_value']);
        $st = $this->db->prepare(
            'INSERT IGNORE INTO ndb_agent_alerts (tenant_id, case_id, kind, point, flow, mail_value, system_value, order_id, source, executed, sig, status, created)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())'
        );
        $st->execute([
            $tenantId, $caseId, $a['kind'], $a['point'], ($a['flow'] ?? null) ?: null, mb_substr($a['mail_value'], 0, 255), mb_substr($a['system_value'], 0, 255),
            ($a['order_id'] ?? null) ?: null, mb_substr((string) ($a['source'] ?? ''), 0, 120), $executed ? 1 : 0, $sig, $executed ? self::DAILY : self::PENDING,
        ]);

        return $st->rowCount() === 1 ? (int) $this->db->lastInsertId() : null;
    }

    /**
     * Avisos pendientes para el resumen por hora, con los datos de la carga y del correo.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingForNotify(int $tenantId, int $limit = 200): array
    {
        $st = $this->db->prepare(
            "SELECT a.*, c.container, c.booking, c.shipment_ref, c.received AS case_received, m.subject AS mail_subject
               FROM ndb_agent_alerts a
               JOIN ndb_agent_cases c ON c.case_id = a.case_id
               LEFT JOIN ndb_agent_messages m ON m.message_id = c.message_id
              WHERE a.tenant_id = ? AND a.status = 'pendiente' AND a.executed = 0
              ORDER BY a.alert_id LIMIT " . max(1, $limit)
        );
        $st->execute([$tenantId]);

        return $st->fetchAll();
    }

    public function markSent(int $alertId, ?int $outboxId): void
    {
        $this->db->prepare("UPDATE ndb_agent_alerts SET status = 'enviado', notified = UTC_TIMESTAMP(), outbox_id = ? WHERE alert_id = ? AND status = 'pendiente'")
            ->execute([$outboxId, $alertId]);
    }

    /** El sistema ya trae el dato: el aviso se descarta en silencio. */
    public function markRevalidated(int $alertId): void
    {
        $this->db->prepare("UPDATE ndb_agent_alerts SET status = 'descartado_revalidado', notified = UTC_TIMESTAMP() WHERE alert_id = ? AND status = 'pendiente'")
            ->execute([$alertId]);
    }

    /**
     * Avisos que van al resumen diario: los de tramos ya ejecutados creados hoy (hora UTC del día dado).
     *
     * @return list<array<string,mixed>>
     */
    public function forDailySummary(int $tenantId, string $sinceUtc): array
    {
        $st = $this->db->prepare(
            "SELECT a.*, c.container, c.booking, c.shipment_ref, m.subject AS mail_subject
               FROM ndb_agent_alerts a
               JOIN ndb_agent_cases c ON c.case_id = a.case_id
               LEFT JOIN ndb_agent_messages m ON m.message_id = c.message_id
              WHERE a.tenant_id = ? AND a.executed = 1 AND a.created >= ?
              ORDER BY a.alert_id"
        );
        $st->execute([$tenantId, $sinceUtc]);

        return $st->fetchAll();
    }

    /** ✓ (1) o ✗ (0) de quien recibió el aviso. Solo se guarda la primera opinión. */
    public function setFeedback(int $alertId, int $value, ?int $userId): bool
    {
        $st = $this->db->prepare('UPDATE ndb_agent_alerts SET feedback = ?, feedback_by = ?, feedback_at = UTC_TIMESTAMP() WHERE alert_id = ? AND feedback IS NULL');
        $st->execute([$value === 1 ? 1 : 0, $userId, $alertId]);

        return $st->rowCount() === 1;
    }

    /** @return array<string,mixed>|null */
    public function get(int $alertId, int $tenantId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM ndb_agent_alerts WHERE alert_id = ? AND tenant_id = ?');
        $st->execute([$alertId, $tenantId]);
        $r = $st->fetch();

        return $r === false ? null : $r;
    }

    /** @return array{enviados:int,revalidados:int,informativos:int,pendientes:int} conteos desde $sinceUtc */
    public function counts(int $tenantId, string $sinceUtc): array
    {
        $st = $this->db->prepare(
            "SELECT COALESCE(SUM(status = 'enviado'),0), COALESCE(SUM(status = 'descartado_revalidado'),0),
                    COALESCE(SUM(executed = 1),0), COALESCE(SUM(status = 'pendiente'),0)
               FROM ndb_agent_alerts WHERE tenant_id = ? AND created >= ?"
        );
        $st->execute([$tenantId, $sinceUtc]);
        $r = $st->fetch(PDO::FETCH_NUM) ?: [0, 0, 0, 0];

        return ['enviados' => (int) $r[0], 'revalidados' => (int) $r[1], 'informativos' => (int) $r[2], 'pendientes' => (int) $r[3]];
    }
}
