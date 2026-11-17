<?php

declare(strict_types=1);

namespace Agente\Persistence;

use PDO;

final class Mailboxes
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** Casilla Gmail pendiente de autorizar: inactiva y sin credencial hasta que el dueño la autorice. */
    public function createPendingGmail(int $tenantId, string $email, string $folders, ?string $filterJson): int
    {
        $this->db->prepare(
            "INSERT INTO ndb_agent_mailboxes (tenant_id, name, email, source_type, auth_type, username, secret_enc, folders, filter_from, active)
             VALUES (?, ?, ?, 'gmail_api', 'oauth_google', ?, NULL, ?, ?, 0)"
        )->execute([$tenantId, $email, $email, $email, $folders, $filterJson]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function find(int $mailboxId, int $tenantId): ?array
    {
        $st = $this->db->prepare('SELECT mailbox_id, email, source_type, secret_enc, active FROM ndb_agent_mailboxes WHERE mailbox_id = ? AND tenant_id = ?');
        $st->execute([$mailboxId, $tenantId]);
        $r = $st->fetch();

        return $r === false ? null : $r;
    }

    /**
     * Guarda la credencial cifrada y activa la casilla. Atómico y de un solo uso: solo funciona mientras la casilla
     * no tenga credencial, así el enlace de conexión no puede reutilizarse ni pisar una casilla ya conectada.
     */
    public function completeGmail(int $mailboxId, int $tenantId, string $secretEnc): bool
    {
        $st = $this->db->prepare(
            "UPDATE ndb_agent_mailboxes SET secret_enc = ?, active = 1, last_error = NULL
             WHERE mailbox_id = ? AND tenant_id = ? AND source_type = 'gmail_api' AND secret_enc IS NULL"
        );
        $st->execute([$secretEnc, $mailboxId, $tenantId]);

        return $st->rowCount() === 1;
    }
}
