<?php

declare(strict_types=1);

namespace Agente\Mail;

use Agente\Attachments\AttachmentReader;
use Agente\Support\Config;
use Agente\Support\Logger;
use PDO;
use Throwable;

/**
 * Fase 1: ingesta SIN IA. Lee casillas (solo lectura), aplica el filtro previo, deduplica por Message-ID,
 * guarda mensajes y adjuntos (texto/OCR + validación) y avanza el checkpoint por carpeta.
 */
final class Ingestor
{
    public function __construct(
        private readonly PDO $db,
        private readonly Config $config,
        private readonly Logger $log,
        private readonly MailSourceFactory $factory,
        private readonly AttachmentReader $reader,
    ) {
    }

    /** @return array{mailboxes:int,new:int,discarded:int,duplicates:int,attachments:int,errors:int} */
    public function run(int $tenantId): array
    {
        $stats = ['mailboxes' => 0, 'new' => 0, 'discarded' => 0, 'duplicates' => 0, 'attachments' => 0, 'errors' => 0];
        $st = $this->db->prepare('SELECT * FROM ndb_agent_mailboxes WHERE tenant_id = ? AND active = 1');
        $st->execute([$tenantId]);
        foreach ($st->fetchAll() as $mb) {
            $stats['mailboxes']++;
            try {
                $this->runMailbox($tenantId, $mb, $stats);
                $this->db->prepare('UPDATE ndb_agent_mailboxes SET last_ok = UTC_TIMESTAMP(), last_error = NULL WHERE mailbox_id = ?')
                    ->execute([$mb['mailbox_id']]);
            } catch (Throwable $e) {
                $stats['errors']++;
                $msg = substr($e::class . ': ' . $e->getMessage(), 0, 500);
                $this->log->error("Casilla {$mb['email']}: {$msg}");
                $this->db->prepare('UPDATE ndb_agent_mailboxes SET last_error = ? WHERE mailbox_id = ?')
                    ->execute([$msg, $mb['mailbox_id']]);
            }
        }

        return $stats;
    }

    /** @param array<string,mixed> $mb @param array<string,int> $stats */
    private function runMailbox(int $tenantId, array $mb, array &$stats): void
    {
        $filter = $this->filterFor($mb);
        $source = $this->factory->create($mb);
        $limit = $this->config->int('INGEST_BATCH', 50);

        foreach (array_filter(array_map('trim', explode(',', (string) $mb['folders']))) as $folder) {
            $cp = $this->loadCheckpoint((int) $mb['mailbox_id'], $folder);
            $batch = $source->fetch($folder, $cp, $limit, static fn (string $from, string $subject): ?string => $filter->reason($from, $subject));
            foreach ($batch->messages as $msg) {
                $this->store($tenantId, (int) $mb['mailbox_id'], $msg, $stats);
            }
            // El checkpoint avanza solo después de guardar el lote completo.
            $this->saveCheckpoint($tenantId, (int) $mb['mailbox_id'], $folder, $batch->next);
            $this->log->info(sprintf('Casilla %s / %s: %d mensajes en el lote', $mb['email'], $folder, count($batch->messages)));
        }
    }

    /** @param array<string,mixed> $mb */
    private function filterFor(array $mb): MailFilter
    {
        $allowed = $this->config->list('MAIL_ALLOWED_SENDERS');
        $discard = $this->config->list('MAIL_DISCARD_SENDERS');
        if (!empty($mb['filter_from'])) {
            $f = json_decode((string) $mb['filter_from'], true);
            if (is_array($f)) {
                $allowed = array_values(array_map('strval', $f['allowed'] ?? $allowed));
                $discard = array_values(array_map('strval', $f['discard'] ?? $discard));
            }
        }

        return new MailFilter($allowed, $discard);
    }

    /** @param array<string,int> $stats */
    private function store(int $tenantId, int $mailboxId, MailMessage $m, array &$stats): void
    {
        $exists = $this->db->prepare('SELECT 1 FROM ndb_agent_messages WHERE tenant_id = ? AND rfc_message_id = ?');
        $exists->execute([$tenantId, substr($m->rfcMessageId, 0, 255)]);
        if ($exists->fetchColumn() !== false) {
            $stats['duplicates']++;

            return;
        }

        $this->db->beginTransaction();
        try {
            $discarded = $m->discardReason !== null;
            $this->db->prepare(
                'INSERT INTO ndb_agent_messages (tenant_id, mailbox_id, rfc_message_id, folder, source_id, received, sender, subject, body_text, status, discard_reason)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $tenantId, $mailboxId, substr($m->rfcMessageId, 0, 255), $m->folder, $m->sourceId,
                $m->receivedAt->format('Y-m-d H:i:s'), substr($m->fromAddress, 0, 255), substr($m->subject, 0, 255),
                $discarded ? null : mb_substr($m->bodyText, 0, 500_000),
                $discarded ? 'descartado' : 'listo', $m->discardReason,
            ]);
            $messageId = (int) $this->db->lastInsertId();

            if ($discarded) {
                $stats['discarded']++;
            } else {
                $stats['new']++;
                $stats['attachments'] += $this->storeAttachments($tenantId, $messageId, $m);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function storeAttachments(int $tenantId, int $messageId, MailMessage $m): int
    {
        $maxImages = $this->config->int('ATTACH_MAX_IMAGES', 4);
        $images = 0;
        $n = 0;
        $ins = $this->db->prepare(
            'INSERT INTO ndb_agent_attachments (tenant_id, message_id, filename, mime, size_bytes, sha1, part_ref, read_method, text_extracted, validation_json, valid, file_path)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($m->attachments as $att) {
            if ($att->isImage() && ++$images > $maxImages) {
                continue;
            }
            $res = $this->reader->read($att);
            $ins->execute([
                $tenantId, $messageId, substr($att->filename, 0, 255), substr($att->mime, 0, 100), $att->size,
                $res->storedPath === null ? null : pathinfo($res->storedPath, PATHINFO_FILENAME), substr($att->partRef, 0, 255), $res->method,
                $res->text === '' ? null : $res->text, json_encode($res->validation, JSON_UNESCAPED_UNICODE),
                $res->valid ? 1 : 0, $res->storedPath,
            ]);
            $n++;
        }

        return $n;
    }

    private function loadCheckpoint(int $mailboxId, string $folder): Checkpoint
    {
        $st = $this->db->prepare('SELECT uid_validity, last_uid, last_ts_ms FROM ndb_agent_checkpoints WHERE mailbox_id = ? AND folder = ?');
        $st->execute([$mailboxId, $folder]);
        $r = $st->fetch();
        if ($r === false) {
            return new Checkpoint();
        }

        return new Checkpoint(
            $r['uid_validity'] === null ? null : (int) $r['uid_validity'],
            $r['last_uid'] === null ? null : (int) $r['last_uid'],
            $r['last_ts_ms'] === null ? null : (int) $r['last_ts_ms'],
        );
    }

    private function saveCheckpoint(int $tenantId, int $mailboxId, string $folder, Checkpoint $cp): void
    {
        $this->db->prepare(
            'INSERT INTO ndb_agent_checkpoints (tenant_id, mailbox_id, folder, uid_validity, last_uid, last_ts_ms, updated)
             VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE uid_validity = VALUES(uid_validity), last_uid = VALUES(last_uid),
               last_ts_ms = VALUES(last_ts_ms), updated = UTC_TIMESTAMP()'
        )->execute([$tenantId, $mailboxId, $folder, $cp->uidValidity, $cp->lastUid, $cp->lastTsMs]);
    }
}
