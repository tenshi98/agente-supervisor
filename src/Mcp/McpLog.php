<?php

declare(strict_types=1);

namespace Agente\Mcp;

use PDO;

/** Una fila por llamada MCP (ndb_agent_mcp_log): permite comprobar que se consulta por lotes. */
final class McpLog
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function record(int $tenantId, string $tool, int $items, int $okItems, int $ms, ?string $error, string $process): void
    {
        $this->db->prepare(
            'INSERT INTO ndb_agent_mcp_log (tenant_id, created, tool, items, ok_items, ms, error, process) VALUES (?, UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?)'
        )->execute([$tenantId, $tool, $items, $okItems, $ms, $error === null ? null : mb_substr($error, 0, 300), $process === '' ? null : mb_substr($process, 0, 30)]);
    }
}
