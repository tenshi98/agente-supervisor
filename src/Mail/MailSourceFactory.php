<?php

declare(strict_types=1);

namespace Agente\Mail;

use Agente\Support\Config;
use Agente\Support\Vault;
use RuntimeException;

/** Construye la fuente correcta a partir de una fila de ndb_agent_mailboxes. */
final class MailSourceFactory
{
    public function __construct(private readonly Config $config, private readonly Vault $vault)
    {
    }

    /** @param array<string,mixed> $mailbox */
    public function create(array $mailbox): MailSource
    {
        if ((string) $mailbox['source_type'] === 'folder') {
            return new FolderSource((string) $mailbox['host']);
        }
        $secret = [];
        if (!empty($mailbox['secret_enc'])) {
            $secret = json_decode($this->vault->decrypt((string) $mailbox['secret_enc']), true, 512, JSON_THROW_ON_ERROR);
        }
        $timeout = $this->config->int('HTTP_TIMEOUT', 30);

        return match ((string) $mailbox['source_type']) {
            'gmail_api' => GmailApiSource::fromRefreshToken(
                $this->config->require('GOOGLE_CLIENT_ID'),
                $this->config->require('GOOGLE_CLIENT_SECRET'),
                (string) ($secret['refresh_token'] ?? throw new RuntimeException('La casilla no tiene refresh_token')),
                $timeout,
                $this->config->int('HTTP_CONNECT_TIMEOUT', 10),
            ),
            'imap' => new ImapSource(
                (string) $mailbox['host'],
                (int) $mailbox['port'],
                (string) $mailbox['security'],
                (string) $mailbox['username'],
                (string) ($secret['password'] ?? throw new RuntimeException('La casilla no tiene password')),
                '',
                $timeout,
            ),
            default => throw new RuntimeException('Tipo de fuente desconocido: ' . $mailbox['source_type']),
        };
    }
}
