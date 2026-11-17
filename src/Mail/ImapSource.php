<?php

declare(strict_types=1);

namespace Agente\Mail;

use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * IMAP genérico (Gmail con contraseña de aplicación u OAuth, Microsoft 365, hosting propio...).
 * Solo lectura: se usa FT_PEEK (leaveUnread) para no marcar como leído; nunca se mueve ni borra.
 * Checkpoint por carpeta: UIDVALIDITY + último UID.
 */
final class ImapSource implements MailSource
{
    private ?Client $client = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,   // ssl | tls | starttls | none
        private readonly string $username,
        private readonly string $password,
        private readonly string $authentication = '', // '' = login normal, 'oauth' = XOAUTH2 (password = access token)
        private readonly int $timeout = 30,
        private readonly int $initialLookbackSeconds = 10_800,
        private readonly bool $validateCert = true,
    ) {
    }

    public function testConnection(): void
    {
        $this->client()->checkConnection();
    }

    public function fetch(string $folder, Checkpoint $since, int $limit, callable $prefilter): MailBatch
    {
        $f = $this->client()->getFolderByPath($folder);
        if ($f === null) {
            throw new \RuntimeException("IMAP: no existe la carpeta '{$folder}'");
        }
        $status = $f->status();
        $validity = isset($status['uidvalidity']) ? (int) $status['uidvalidity'] : null;
        $lastUid = $since->lastUid;
        if ($lastUid !== null && $since->uidValidity !== null && $validity !== null && $validity !== $since->uidValidity) {
            $lastUid = null; // UIDVALIDITY cambió: los UID anteriores ya no valen, se relee por fecha (dedupe por Message-ID)
        }

        $query = $f->query()->leaveUnread();
        $messages = $lastUid !== null
            ? $query->getByUidGreater($lastUid)
            : $query->since(Carbon::now()->subSeconds($this->initialLookbackSeconds))->get();

        $list = [];
        foreach ($messages as $m) {
            $list[] = $m;
        }
        usort($list, static fn (Message $a, Message $b): int => (int) $a->getUid() <=> (int) $b->getUid());
        $list = array_slice($list, 0, $limit);

        $out = [];
        $maxUid = $lastUid ?? 0;
        foreach ($list as $m) {
            $uid = (int) $m->getUid();
            $fromAttr = $m->getFrom()->first();
            $fromAddr = strtolower((string) ($fromAttr->mail ?? ''));
            $fromName = (string) ($fromAttr->personal ?? '');
            $subject = (string) $m->getSubject();
            $rfc = trim((string) $m->getMessageId()) ?: sprintf('imap-%s-%s-%s-%d@local', $this->username, $folder, $validity ?? 0, $uid);
            $dateAttr = $m->getDate()->first();
            $date = $dateAttr instanceof \DateTimeInterface
                ? DateTimeImmutable::createFromInterface($dateAttr)->setTimezone(new DateTimeZone('UTC'))
                : new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $reason = $prefilter($fromAddr, $subject);
            if ($reason !== null) {
                $out[] = new MailMessage((string) $uid, $folder, $rfc, $date, $fromName, $fromAddr, $subject, '', [], $uid, $reason);
            } else {
                $text = trim($m->getTextBody());
                if ($text === '') {
                    $text = GmailApiSource::htmlToText($m->getHTMLBody());
                }
                $out[] = new MailMessage((string) $uid, $folder, $rfc, $date, $fromName, $fromAddr, $subject, $text, $this->attachments($m), $uid);
            }
            $maxUid = max($maxUid, $uid);
        }

        return new MailBatch($out, new Checkpoint($validity, $maxUid > 0 ? $maxUid : $lastUid));
    }

    /** @return list<MailAttachment> */
    private function attachments(Message $m): array
    {
        $atts = [];
        foreach ($m->getAttachments() as $a) {
            $content = (string) $a->getContent();
            $atts[] = new MailAttachment(
                (string) ($a->getName() ?: 'adjunto'),
                (string) ($a->getMimeType() ?? 'application/octet-stream'),
                (int) $a->getSize(),
                (string) $a->getPartNumber(),
                static fn (): string => $content,
            );
        }

        return $atts;
    }

    private function client(): Client
    {
        if ($this->client === null) {
            $config = [
                'host' => $this->host,
                'port' => $this->port,
                'encryption' => in_array($this->encryption, ['none', ''], true) ? false : ($this->encryption === 'starttls' ? 'starttls' : $this->encryption),
                'validate_cert' => $this->validateCert,
                'username' => $this->username,
                'password' => $this->password,
                'protocol' => 'imap',
                'timeout' => $this->timeout,
            ];
            if ($this->authentication !== '') {
                $config['authentication'] = $this->authentication;
            }
            $this->client = (new ClientManager())->make($config);
            $this->client->connect();
        }

        return $this->client;
    }

    public function __destruct()
    {
        try {
            $this->client?->disconnect();
        } catch (\Throwable) {
            // sin efecto
        }
    }
}
