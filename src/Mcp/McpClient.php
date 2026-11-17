<?php

declare(strict_types=1);

namespace Agente\Mcp;

/** Cliente de las herramientas del sistema externo. Regla de oro: una llamada por lote, nunca por elemento. */
interface McpClient
{
    /**
     * @param list<array<string,mixed>> $items cada uno con 'ref' (string único)
     * @return array<string,array<string,mixed>> una entrada por cada ref enviado; cada resultado trae 'status' = ok|no_encontrado|error
     */
    public function batch(int $tenantId, string $tool, array $items, string $process = ''): array;
}
