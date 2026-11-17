<?php

declare(strict_types=1);

namespace Agente\Mcp;

/** Doble de pruebas / modo MCP_URL=fake. Cada handler: callable(array $item): array (el resultado sin 'ref'). */
final class FakeMcpClient implements McpClient
{
    /** @var list<array{0:string,1:int}> */
    public array $calls = [];

    /** @param array<string,callable> $handlers tool => callable */
    public function __construct(private readonly array $handlers = [])
    {
    }

    public function batch(int $tenantId, string $tool, array $items, string $process = ''): array
    {
        $unique = [];
        foreach ($items as $it) {
            $unique[(string) $it['ref']] ??= $it;
        }
        $this->calls[] = [$tool, count($unique)];
        $out = [];
        foreach ($unique as $ref => $it) {
            $h = $this->handlers[$tool] ?? null;
            $r = $h === null ? ['status' => 'no_encontrado'] : $h($it);
            $r['ref'] = (string) $ref;
            $r['status'] = $r['status'] ?? 'ok';
            $out[(string) $ref] = $r;
        }

        return $out;
    }
}
