<?php

declare(strict_types=1);

namespace Agente\Persistence;

use PDO;

/** Usuarios autorizados a decidir. Los destinatarios salen SIEMPRE de aquí, nunca del contenido de un correo. */
final class Recipients
{
    public function __construct(private readonly PDO $db)
    {
    }

    public const ROLES = ['duda', 'aviso', 'resumen'];

    /** @return list<array<string,mixed>> destinatarios activos de un rol (duda = decide; aviso = resumen por hora; resumen = diario) */
    public function byRole(int $tenantId, string $role): array
    {
        $st = $this->db->prepare('SELECT * FROM ndb_agent_recipients WHERE tenant_id = ? AND role = ? AND active = 1 ORDER BY recipient_id');
        $st->execute([$tenantId, $role]);

        return $st->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function deciders(int $tenantId): array
    {
        return $this->byRole($tenantId, 'duda');
    }

    /** @return array<string,mixed>|null destinatario `duda` activo con ese correo (para validar quién responde por correo) */
    public function deciderByEmail(int $tenantId, string $email): ?array
    {
        $st = $this->db->prepare("SELECT * FROM ndb_agent_recipients WHERE tenant_id = ? AND role = 'duda' AND active = 1 AND LOWER(email) = LOWER(?) LIMIT 1");
        $st->execute([$tenantId, trim($email)]);
        $r = $st->fetch();

        return $r === false ? null : $r;
    }

    /** @return array<string,mixed>|null solo si es un decisor activo del tenant */
    public function activeDecider(int $recipientId, int $tenantId): ?array
    {
        $st = $this->db->prepare("SELECT * FROM ndb_agent_recipients WHERE recipient_id = ? AND tenant_id = ? AND role = 'duda' AND active = 1");
        $st->execute([$recipientId, $tenantId]);
        $r = $st->fetch();

        return $r === false ? null : $r;
    }
}
