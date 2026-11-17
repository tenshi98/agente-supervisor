<?php

declare(strict_types=1);

namespace Agente\Mail;

use DateTimeImmutable;
use DateTimeZone;
use Google\Client;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;
use GuzzleHttp\Client as Http;
use RuntimeException;

/**
 * Gmail API con alcance gmail.readonly (no puede modificar nada).
 * "Carpeta" = etiqueta (label) de Gmail; INBOX es la etiqueta de sistema.
 */
final class GmailApiSource implements MailSource
{
    /** Margen para no leer correos a medio entregar (mismo criterio que el prototipo). */
    private const SETTLE_MS = 120_000;

    private Gmail $gmail;
    /** @var array<string,string>|null nombre de etiqueta => id */
    private ?array $labels = null;

    public function __construct(
        private readonly Client $client,
        private readonly int $initialLookbackSeconds = 10_800,
    ) {
        $this->gmail = new Gmail($client);
    }

    public static function fromRefreshToken(
        string $clientId,
        string $clientSecret,
        string $refreshToken,
        int $timeout = 30,
        int $connectTimeout = 10,
    ): self {
        $client = new Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setScopes([Gmail::GMAIL_READONLY]);
        $client->setAccessType('offline');
        $client->setHttpClient(new Http(['timeout' => $timeout, 'connect_timeout' => $connectTimeout]));
        $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        if (isset($token['error'])) {
            throw new RuntimeException('Gmail: no se pudo renovar el token (' . ($token['error_description'] ?? $token['error']) . ')');
        }

        return new self($client);
    }

    public function testConnection(): void
    {
        $this->gmail->users->getProfile('me');
    }

    public function fetch(string $folder, Checkpoint $since, int $limit, callable $prefilter): MailBatch
    {
        $labelId = $this->labelId($folder);
        $untilMs = (int) (microtime(true) * 1000) - self::SETTLE_MS;
        $sinceMs = $since->lastTsMs ?? ((int) (microtime(true) * 1000) - $this->initialLookbackSeconds * 1000);
        $afterSec = max(0, intdiv($sinceMs - self::SETTLE_MS, 1000));

        // 1) Solo ids que cumplen etiqueta y fecha
        $ids = [];
        $page = null;
        do {
            $params = ['labelIds' => [$labelId], 'q' => 'after:' . $afterSec, 'maxResults' => 100];
            if ($page !== null) {
                $params['pageToken'] = $page;
            }
            $res = $this->gmail->users_messages->listUsersMessages('me', $params);
            foreach ($res->getMessages() ?? [] as $m) {
                $ids[] = (string) $m->getId();
            }
            $page = $res->getNextPageToken();
        } while ($page !== null && count($ids) < $limit * 3);

        // 2) Cabeceras (barato) -> filtro previo -> solo entonces se baja el mensaje completo
        $meta = [];
        foreach ($ids as $id) {
            $m = $this->gmail->users_messages->get('me', $id, [
                'format' => 'metadata',
                'metadataHeaders' => ['From', 'Subject', 'Message-ID', 'Message-Id'],
            ]);
            $ms = (int) $m->getInternalDate();
            if ($ms > $untilMs) {
                continue; // demasiado reciente: se leerá en la próxima corrida
            }
            // Lo ya visto dentro del margen se vuelve a listar; la deduplicación por Message-ID lo descarta.
            $meta[] = [$ms, $id, $m];
        }
        usort($meta, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $meta = array_slice($meta, 0, $limit);

        $out = [];
        $maxMs = $since->lastTsMs ?? 0;
        foreach ($meta as [$ms, $id, $m]) {
            $h = $this->headers($m->getPayload());
            [$fromName, $fromAddr] = self::parseFrom($h['from'] ?? '');
            $subject = $h['subject'] ?? '';
            $rfc = trim($h['message-id'] ?? '') ?: ('gmail-' . $id . '@local');
            $date = (new DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new DateTimeZone('UTC'));
            $reason = $prefilter($fromAddr, $subject);
            if ($reason !== null) {
                $out[] = new MailMessage($id, $folder, $rfc, $date, $fromName, $fromAddr, $subject, '', [], $ms, $reason);
            } else {
                $full = $this->gmail->users_messages->get('me', $id, ['format' => 'full']);
                $out[] = $this->build($full, $folder, $rfc, $date, $fromName, $fromAddr, $subject, $ms);
            }
            $maxMs = max($maxMs, $ms);
        }

        return new MailBatch($out, new Checkpoint(null, null, $maxMs > 0 ? $maxMs : $since->lastTsMs));
    }

    private function labelId(string $name): string
    {
        if ($this->labels === null) {
            $this->labels = [];
            foreach ($this->gmail->users_labels->listUsersLabels('me')->getLabels() ?? [] as $l) {
                $this->labels[(string) $l->getName()] = (string) $l->getId();
            }
        }
        if (isset($this->labels[$name])) {
            return $this->labels[$name];
        }
        foreach ($this->labels as $n => $id) {
            if (strcasecmp($n, $name) === 0) {
                return $id;
            }
        }
        throw new RuntimeException("Gmail: no existe la etiqueta '{$name}'");
    }

    /** @return array<string,string> */
    private function headers(?MessagePart $part): array
    {
        $h = [];
        foreach ($part?->getHeaders() ?? [] as $x) {
            $h[strtolower((string) $x->getName())] = (string) $x->getValue();
        }

        return $h;
    }

    /** @return array{0:string,1:string} */
    public static function parseFrom(string $from): array
    {
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/', $from, $m) === 1) {
            return [trim($m[1]), strtolower(trim($m[2]))];
        }

        return ['', strtolower(trim($from))];
    }

    private function build(
        Message $m,
        string $folder,
        string $rfc,
        DateTimeImmutable $date,
        string $fromName,
        string $fromAddr,
        string $subject,
        int $ms,
    ): MailMessage {
        $text = '';
        $html = '';
        $atts = [];
        $this->walk($m->getPayload(), (string) $m->getId(), $text, $html, $atts);
        if (trim($text) === '' && $html !== '') {
            $text = self::htmlToText($html);
        }

        return new MailMessage((string) $m->getId(), $folder, $rfc, $date, $fromName, $fromAddr, $subject, trim($text), $atts, $ms);
    }

    /** @param list<MailAttachment> $atts */
    private function walk(?MessagePart $part, string $msgId, string &$text, string &$html, array &$atts): void
    {
        if ($part === null) {
            return;
        }
        $mime = (string) $part->getMimeType();
        $filename = (string) $part->getFilename();
        $body = $part->getBody();
        if ($filename !== '' && $body !== null && ($body->getAttachmentId() !== null || $body->getData() !== null)) {
            $attId = (string) $body->getAttachmentId();
            $inline = $body->getData();
            $gmail = $this->gmail;
            $atts[] = new MailAttachment(
                $filename,
                $mime,
                (int) $body->getSize(),
                $attId,
                static function () use ($gmail, $msgId, $attId, $inline): string {
                    $data = $inline ?? $gmail->users_messages_attachments->get('me', $msgId, $attId)->getData();

                    return (string) base64_decode(strtr((string) $data, '-_', '+/'), true);
                },
            );
        } elseif ($body !== null && $body->getData() !== null) {
            $decoded = (string) base64_decode(strtr((string) $body->getData(), '-_', '+/'), true);
            if ($mime === 'text/plain') {
                $text .= $decoded . "\n";
            } elseif ($mime === 'text/html') {
                $html .= $decoded . "\n";
            }
        }
        foreach ($part->getParts() ?? [] as $child) {
            $this->walk($child, $msgId, $text, $html, $atts);
        }
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b.*?</\1>#si', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>|</(p|div|tr|li|h[1-6])>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $text) ?? $text) ?? $text);
    }
}
