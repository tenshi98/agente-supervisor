<?php

declare(strict_types=1);

namespace Agente\Mcp;

/** Herramientas `buscar_ordenes`, `tramos_de_ordenes` y `revalidar_avisos`. Todas por lote. */
final class OrdersClient
{
    public function __construct(private readonly McpClient $mcp)
    {
    }

    /**
     * @param list<array{ref:string,contenedor:string,despacho:string,booking:string,fecha_correo:string}> $cargas
     * @return array<string,array{status:string,orden:?array,unidad:?array}>
     */
    public function find(int $tenantId, array $cargas, string $process = ''): array
    {
        if ($cargas === []) {
            return [];
        }
        $out = [];
        foreach ($this->mcp->batch($tenantId, 'buscar_ordenes', $cargas, $process) as $ref => $r) {
            $out[(string) $ref] = ['status' => (string) $r['status'], 'orden' => self::arr($r['orden'] ?? null), 'unidad' => self::arr($r['unidad'] ?? null)];
        }

        return $out;
    }

    /**
     * @param list<array{ref:string,service_id:string,contenedor:string}> $orders
     * @return array<string,array{status:string,tramos:list<array{status_id:int,from_name:string,to_name:string,truck_id:string,date_start:string}>}>
     */
    public function legs(int $tenantId, array $orders, string $process = ''): array
    {
        if ($orders === []) {
            return [];
        }
        $out = [];
        foreach ($this->mcp->batch($tenantId, 'tramos_de_ordenes', $orders, $process) as $ref => $r) {
            $out[(string) $ref] = ['status' => (string) $r['status'], 'tramos' => self::legList($r['tramos'] ?? null)];
        }

        return $out;
    }

    /**
     * @param list<array{ref:string,contenedor:string,despacho:string,booking:string,fecha_correo:string,puntos:array<string,string>}> $avisos
     * @return array<string,array{status:string,orden:?array,unidad:?array,tramos:list<array>}>
     */
    public function revalidate(int $tenantId, array $avisos, string $process = ''): array
    {
        if ($avisos === []) {
            return [];
        }
        $out = [];
        foreach ($this->mcp->batch($tenantId, 'revalidar_avisos', $avisos, $process) as $ref => $r) {
            $out[(string) $ref] = [
                'status' => (string) $r['status'], 'orden' => self::arr($r['orden'] ?? null),
                'unidad' => self::arr($r['unidad'] ?? null), 'tramos' => self::legList($r['tramos'] ?? null),
            ];
        }

        return $out;
    }

    /** @return ?array<string,mixed> */
    private static function arr(mixed $v): ?array
    {
        return is_array($v) ? $v : null;
    }

    /** @return list<array{status_id:int,from_name:string,to_name:string,truck_id:string,date_start:string}> */
    private static function legList(mixed $v): array
    {
        $out = [];
        foreach (is_array($v) ? $v : [] as $t) {
            if (is_array($t)) {
                $out[] = [
                    'status_id' => (int) ($t['status_id'] ?? 0), 'from_name' => (string) ($t['from_name'] ?? ''),
                    'to_name' => (string) ($t['to_name'] ?? ''), 'truck_id' => (string) ($t['truck_id'] ?? ''),
                    'date_start' => (string) ($t['date_start'] ?? ''),
                ];
            }
        }

        return $out;
    }
}
