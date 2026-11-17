<?php

declare(strict_types=1);

namespace Agente\Persistence;

use Agente\Llm\LlmResponse;
use PDO;

final class Usage
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function record(int $tenantId, string $purpose, LlmResponse $r, ?int $messageId): void
    {
        $this->db->prepare('INSERT INTO ndb_agent_usage (tenant_id, created, purpose, model, tokens_in, tokens_out, cost_usd, message_id) VALUES (?, UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?)')
            ->execute([$tenantId, $purpose, mb_substr($r->model, 0, 60), $r->tokensIn, $r->tokensOut, $r->costUsd, $messageId]);
    }

    /** Gasto de IA del día (UTC) en dólares. */
    public function costToday(int $tenantId): float
    {
        $st = $this->db->prepare('SELECT COALESCE(SUM(cost_usd), 0) FROM ndb_agent_usage WHERE tenant_id = ? AND created >= UTC_DATE()');
        $st->execute([$tenantId]);

        return (float) $st->fetchColumn();
    }
}
