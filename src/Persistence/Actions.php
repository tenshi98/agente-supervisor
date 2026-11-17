<?php

declare(strict_types=1);

namespace Agente\Persistence;

use PDO;

/**
 * Bitácora idempotente de acciones (reglas/acciones.json): una decisión produce una acción sobre un objeto una sola vez.
 * La clave única (tenant, decisión, acción, ref) evita duplicar avisos, dudas o equivalencias al reprocesar.
 */
final class Actions
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** true si la acción es nueva (hay que ejecutarla); false si ya se ejecutó antes. @param array<string,mixed> $detail */
    public function once(int $tenantId, string $decision, string $action, string $ref, array $detail = []): bool
    {
        $st = $this->db->prepare(
            'INSERT IGNORE INTO ndb_agent_actions (tenant_id, decision, action, ref, detail_json, created) VALUES (?,?,?,?,?,UTC_TIMESTAMP())'
        );
        $st->execute([$tenantId, mb_substr($decision, 0, 30), mb_substr($action, 0, 30), mb_substr($ref, 0, 190), $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE)]);

        return $st->rowCount() === 1;
    }
}
