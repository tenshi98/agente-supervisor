<?php

declare(strict_types=1);

namespace Agente\Mcp;

use Agente\Text\Places;

/**
 * Servidor MCP de mentira para demos y pruebas manuales (MCP_URL=fake): responde desde un archivo JSON.
 *
 *   {
 *     "genericos": ["san antonio", "valparaiso"],
 *     "catalogo": [ {"place_id":"P1","nombre":"PUERTO CENTRAL","direccion":"...","comuna":"...","categoria":"puerto","activo":true,"marca":null} ],
 *     "ordenes":  [ {"service_id":"S1","tipo":"I","shipment":"I-2084123","reference":"BK1","contenedor":"ABCU1234567",
 *                    "unidad":{"start_name":"PUERTO CENTRAL","delivery_name":"","end_name":"","current_name":"","stacking":false,"lcl_type":""},
 *                    "tramos":[{"status_id":10,"from_name":"...","to_name":"...","truck_id":"T1","date_start":"2026-11-10 08:00:00"}]} ]
 *   }
 */
final class FixtureMcp
{
    public static function fromFile(string $path): FakeMcpClient
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

        return self::fromArray(is_array($data) ? $data : []);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): FakeMcpClient
    {
        $generic = array_map([Places::class, 'normalize'], array_map('strval', (array) ($data['genericos'] ?? [])));
        $catalog = is_array($data['catalogo'] ?? null) ? $data['catalogo'] : [];
        $orders = is_array($data['ordenes'] ?? null) ? $data['ordenes'] : [];

        $find = static function (array $item) use ($orders): ?array {
            foreach ($orders as $o) {
                $cont = (string) ($item['contenedor'] ?? '');
                $ds = (string) ($item['despacho'] ?? '');
                $bk = (string) ($item['booking'] ?? '');
                if (($cont !== '' && ($o['contenedor'] ?? '') === $cont)
                    || ($ds !== '' && ($o['shipment'] ?? '') === $ds)
                    || ($bk !== '' && (($o['reference'] ?? '') === $bk || ($o['shipment'] ?? '') === $bk))
                    || ($item['service_id'] ?? '') !== '' && ($o['service_id'] ?? '') === $item['service_id']) {
                    return $o;
                }
            }

            return null;
        };
        $head = static fn (array $o): array => [
            'orden' => ['service_id' => $o['service_id'] ?? '', 'tipo' => $o['tipo'] ?? '', 'shipment' => $o['shipment'] ?? '', 'reference' => $o['reference'] ?? ''],
            'unidad' => $o['unidad'] ?? [],
        ];

        return new FakeMcpClient([
            'catalogo_lugares' => static function (array $item) use ($catalog, $generic): array {
                $norm = Places::normalize((string) $item['texto']);
                if (in_array($norm, $generic, true)) {
                    return ['status' => 'ok', 'candidatos' => [], 'es_generico' => true, 'marca' => null];
                }
                $c = [];
                foreach ($catalog as $p) {
                    $sim = max(Places::similarity((string) $item['texto'], (string) ($p['nombre'] ?? '')), (int) round(0.85 * Places::similarity((string) $item['texto'], (string) ($p['direccion'] ?? ''))));
                    if ($sim > 0 && ($p['activo'] ?? true)) {
                        $c[] = $p + ['similitud' => $sim];
                    }
                }
                usort($c, static fn (array $a, array $b): int => $b['similitud'] <=> $a['similitud']);
                $c = array_slice($c, 0, 6);

                return ['status' => 'ok', 'candidatos' => $c, 'es_generico' => false, 'marca' => null];
            },
            'buscar_ordenes' => static function (array $item) use ($find, $head): array {
                $o = $find($item);

                return $o === null ? ['status' => 'no_encontrado'] : ['status' => 'ok'] + $head($o);
            },
            'tramos_de_ordenes' => static function (array $item) use ($find): array {
                $o = $find($item);

                return $o === null ? ['status' => 'no_encontrado'] : ['status' => 'ok', 'tramos' => $o['tramos'] ?? []];
            },
            'revalidar_avisos' => static function (array $item) use ($find, $head): array {
                $o = $find($item);

                return $o === null ? ['status' => 'no_encontrado'] : ['status' => 'ok'] + $head($o) + ['tramos' => $o['tramos'] ?? []];
            },
        ]);
    }
}
