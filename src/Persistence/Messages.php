<?php

declare(strict_types=1);

namespace Agente\Persistence;

use PDO;

final class Messages
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> correos ingeridos que esperan comprensión o nueva decisión */
    public function ready(int $tenantId, int $limit): array
    {
        $st = $this->db->prepare("SELECT * FROM ndb_agent_messages WHERE tenant_id = ? AND status = 'listo' ORDER BY received, message_id LIMIT " . max(1, $limit));
        $st->execute([$tenantId]);

        return $st->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function attachments(int $messageId): array
    {
        $st = $this->db->prepare('SELECT * FROM ndb_agent_attachments WHERE message_id = ? ORDER BY attachment_id');
        $st->execute([$messageId]);

        return $st->fetchAll();
    }

    public function saveExtraction(int $messageId, string $extractJson, string $tipo, string $flow = 'desconocido'): void
    {
        $this->db->prepare('UPDATE ndb_agent_messages SET extract_json = ?, tipo = ?, flow = ?, error = NULL WHERE message_id = ?')
            ->execute([$extractJson, mb_substr($tipo, 0, 30), mb_substr($flow, 0, 12), $messageId]);
    }

    public function finish(int $messageId, string $status, ?string $resultJson, ?string $discardReason = null): void
    {
        $this->db->prepare('UPDATE ndb_agent_messages SET status = ?, result_json = ?, discard_reason = COALESCE(?, discard_reason), processed = UTC_TIMESTAMP(), error = NULL WHERE message_id = ?')
            ->execute([$status, $resultJson, $discardReason, $messageId]);
    }

    /** Falla de comprensión: se reintenta en la próxima corrida hasta $maxTries; luego queda en 'error'. */
    public function fail(int $messageId, string $error, int $maxTries = 3): void
    {
        $this->db->prepare("UPDATE ndb_agent_messages SET status = IF(tries + 1 >= ?, 'error', status), tries = tries + 1, error = ? WHERE message_id = ?")
            ->execute([$maxTries, mb_substr($error, 0, 500), $messageId]);
    }

    /** Vuelve a 'listo' los correos que esperaban una respuesta, para decidir de nuevo (sin repetir la lectura de la IA). */
    public function reopen(array $messageIds): int
    {
        $ids = array_values(array_unique(array_map('intval', $messageIds)));
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->db->prepare("UPDATE ndb_agent_messages SET status = 'listo' WHERE status = 'pendiente' AND message_id IN ({$in})");
        $st->execute($ids);

        return $st->rowCount();
    }

    /** Borra los originales guardados de correos ya resueltos hace más de $days días. */
    public function purgeOriginals(int $tenantId, int $days): int
    {
        $st = $this->db->prepare(
            "SELECT a.attachment_id, a.file_path FROM ndb_agent_attachments a JOIN ndb_agent_messages m ON m.message_id = a.message_id
             WHERE a.tenant_id = ? AND a.file_path IS NOT NULL AND m.status IN ('procesado','descartado','error')
               AND m.processed < DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . max(1, $days) . ' DAY)'
        );
        $st->execute([$tenantId]);
        $n = 0;
        foreach ($st->fetchAll() as $r) {
            if (is_file((string) $r['file_path'])) {
                @unlink((string) $r['file_path']);
            }
            $this->db->prepare('UPDATE ndb_agent_attachments SET file_path = NULL WHERE attachment_id = ?')->execute([$r['attachment_id']]);
            $n++;
        }

        return $n;
    }

    /**
     * Retención: pasados $days días vacía el cuerpo y el texto de los adjuntos de correos ya resueltos.
     * Quedan los metadatos, las cargas y las decisiones. El agente no tiene DELETE: se vacía con UPDATE.
     *
     * @return int correos vaciados
     */
    public function purgeContent(int $tenantId, int $days): int
    {
        $days = max(1, $days);
        $where = "m.tenant_id = ? AND m.content_purged IS NULL AND m.status IN ('procesado','descartado','error')
                  AND m.created < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$days} DAY)";
        $this->db->prepare("UPDATE ndb_agent_attachments a JOIN ndb_agent_messages m ON m.message_id = a.message_id SET a.text_extracted = NULL WHERE {$where}")->execute([$tenantId]);
        $st = $this->db->prepare("UPDATE ndb_agent_messages m SET m.body_text = NULL, m.content_purged = UTC_TIMESTAMP() WHERE {$where}");
        $st->execute([$tenantId]);

        return $st->rowCount();
    }

    /** Un correo recibido que en realidad es la respuesta a una duda no se procesa como instrucción. */
    public function markAsAnswer(int $messageId): void
    {
        $this->finish($messageId, 'descartado', null, 'respuesta_duda');
    }
}
