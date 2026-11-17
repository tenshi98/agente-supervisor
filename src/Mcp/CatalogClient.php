<?php

declare(strict_types=1);

namespace Agente\Mcp;

/** Herramienta `catalogo_lugares`: candidatos del catálogo (solo puntos activos) para muchos textos en una llamada. */
final class CatalogClient
{
    public function __construct(private readonly McpClient $mcp)
    {
    }

    /**
     * @param list<array{ref:string,texto:string,rol:string,flujo:string}> $places
     * @return array<string,array{status:string,candidatos:list<array{place_id:string,nombre:string,direccion:string,comuna:string,categoria:string,activo:bool,similitud:int}>,es_generico:bool,marca:?string}>
     */
    public function resolve(int $tenantId, array $places, string $process = ''): array
    {
        if ($places === []) {
            return [];
        }
        $out = [];
        foreach ($this->mcp->batch($tenantId, 'catalogo_lugares', $places, $process) as $ref => $r) {
            $cands = [];
            foreach ((is_array($r['candidatos'] ?? null) ? $r['candidatos'] : []) as $c) {
                if (!is_array($c) || (string) ($c['place_id'] ?? '') === '') {
                    continue;
                }
                $cands[] = [
                    'place_id' => (string) $c['place_id'], 'nombre' => (string) ($c['nombre'] ?? ''),
                    'direccion' => (string) ($c['direccion'] ?? ''), 'comuna' => (string) ($c['comuna'] ?? ''),
                    'categoria' => (string) ($c['categoria'] ?? ''), 'activo' => (bool) ($c['activo'] ?? true),
                    'similitud' => (int) ($c['similitud'] ?? 0),
                ];
            }
            $marca = trim((string) ($r['marca'] ?? ''));
            $out[(string) $ref] = [
                'status' => (string) $r['status'], 'candidatos' => $cands,
                'es_generico' => (bool) ($r['es_generico'] ?? false), 'marca' => $marca === '' ? null : $marca,
            ];
        }

        return $out;
    }
}
