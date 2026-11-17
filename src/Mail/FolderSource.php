<?php

declare(strict_types=1);

namespace Agente\Mail;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Origen de correo desde una carpeta local (source_type = 'folder'). Sirve para probar el proceso completo sin
 * credenciales de correo. Cada correo es un archivo .json:
 *   { "message_id": "<id@x>", "from": "ana@cliente.cl", "from_name": "Ana", "subject": "...", "date": "2026-11-17 09:30:00",
 *     "body": "texto", "attachments": [ { "filename": "guia.pdf", "mime": "application/pdf", "path": "adjuntos/guia.pdf" } ] }
 * "path" es relativo a la carpeta. Solo lectura: nunca modifica ni borra archivos.
 */
final class FolderSource implements MailSource
{
    public function __construct(private readonly string $dir)
    {
    }

    public function testConnection(): void
    {
        if (!is_dir($this->dir)) {
            throw new RuntimeException("Carpeta de correos inexistente: {$this->dir}");
        }
    }

    public function fetch(string $folder, Checkpoint $since, int $limit, callable $prefilter): MailBatch
    {
        $root = $folder === '' || strtoupper($folder) === 'INBOX' ? $this->dir : $this->dir . '/' . basename($folder);
        if (!is_dir($root)) {
            throw new RuntimeException("Carpeta de correos inexistente: {$root}");
        }

        $found = [];
        foreach (glob($root . '/*.json') ?: [] as $file) {
            $d = json_decode((string) file_get_contents($file), true);
            if (!is_array($d)) {
                continue;
            }
            $date = new DateTimeImmutable((string) ($d['date'] ?? '@' . filemtime($file)), new DateTimeZone('UTC'));
            $ms = (int) $date->format('U') * 1000;
            if ($since->lastTsMs !== null && $ms < $since->lastTsMs) {
                continue; // lo ya visto con la misma marca se descarta luego por Message-ID
            }
            $found[] = [$ms, $file, $d, $date];
        }
        usort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $found = array_slice($found, 0, $limit);

        $out = [];
        $maxMs = $since->lastTsMs ?? 0;
        foreach ($found as [$ms, $file, $d, $date]) {
            $from = trim((string) ($d['from'] ?? ''));
            $subject = (string) ($d['subject'] ?? '');
            $rfc = trim((string) ($d['message_id'] ?? '')) ?: 'folder-' . basename($file) . '@local';
            $reason = $prefilter($from, $subject);
            $atts = [];
            if ($reason === null) {
                foreach ((array) ($d['attachments'] ?? []) as $a) {
                    $path = $root . '/' . ltrim((string) ($a['path'] ?? ''), '/');
                    if (str_contains((string) ($a['path'] ?? ''), '..') || !is_file($path)) {
                        continue; // no se sale de la carpeta
                    }
                    $atts[] = new MailAttachment(
                        (string) ($a['filename'] ?? basename($path)),
                        (string) ($a['mime'] ?? 'application/octet-stream'),
                        (int) filesize($path),
                        (string) $a['path'],
                        static fn (): string => (string) file_get_contents($path),
                    );
                }
            }
            $out[] = new MailMessage(
                basename($file), $folder, $rfc, $date, (string) ($d['from_name'] ?? ''), $from, $subject,
                $reason === null ? (string) ($d['body'] ?? '') : '', $atts, $ms, $reason,
            );
            $maxMs = max($maxMs, $ms);
        }

        return new MailBatch($out, new Checkpoint(null, null, $maxMs > 0 ? $maxMs : $since->lastTsMs));
    }
}
