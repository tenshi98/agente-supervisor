<?php

declare(strict_types=1);

namespace Agente\Persistence;

use PDO;

final class Outbox
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @param string $kind duda | aviso | resumen | respuesta | enlace_vencido @return int id de la fila (outbox_id) */
    public function record(int $tenantId, ?int $questionId, ?int $recipientId, string $to, string $subject, string $status, ?string $error, ?string $previewPath, string $kind = 'duda'): int
    {
        $this->db->prepare(
            'INSERT INTO ndb_agent_outbox (tenant_id, kind, question_id, recipient_id, to_email, subject, status, error, preview_path, created)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$tenantId, mb_substr($kind, 0, 20), $questionId, $recipientId, mb_substr($to, 0, 150), mb_substr($subject, 0, 255), $status, $error === null ? null : mb_substr($error, 0, 500), $previewPath]);

        return (int) $this->db->lastInsertId();
    }

    /** ¿Ya se generó hoy (UTC) una vista previa o envío de este tipo para este destinatario? Evita repetir en dry_run. */
    public function sentTodayTo(int $tenantId, string $kind, string $to): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM ndb_agent_outbox WHERE tenant_id = ? AND kind = ? AND to_email = ? AND status IN ('enviado','simulado') AND created >= UTC_DATE() LIMIT 1");
        $st->execute([$tenantId, $kind, mb_substr($to, 0, 150)]);

        return $st->fetchColumn() !== false;
    }

    /** ¿Ya hay una vista previa de esta duda para este destinatario? (en dry_run no se repite en cada corrida) */
    public function hasSimulated(int $questionId, int $recipientId): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM ndb_agent_outbox WHERE question_id = ? AND recipient_id = ? AND status = 'simulado' LIMIT 1");
        $st->execute([$questionId, $recipientId]);

        return $st->fetchColumn() !== false;
    }
}
