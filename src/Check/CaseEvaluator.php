<?php

declare(strict_types=1);

namespace Agente\Check;

use Agente\Text\Places;

/**
 * Cruce de UNA carga contra lo que devolvió el sistema externo. Lógica pura: sin BD ni red.
 * Resultados por punto (reglas/00_nomenclatura.md §8): ok, ok_en_ruta, ok_almacenaje, aun_no_requerido,
 * falta_en_sistema, lugar_vacio, distinto, distinto_ejecutado, no_encontrada, esperando_duda, no_verificable.
 */
final class CaseEvaluator
{
    /** Campo del sistema externo que guarda cada punto (reglas/00_nomenclatura.md §9). */
    public const SYSTEM_FIELD = ['inicio' => 'start_name', 'cliente' => 'delivery_name', 'final' => 'end_name'];

    /** Gravedad para resumir una carga en un solo estado (mayor = más grave). */
    private const SEVERITY = [
        'falta_en_sistema' => 9, 'no_encontrada' => 9, 'distinto' => 8, 'lugar_vacio' => 7, 'distinto_ejecutado' => 6,
        'esperando_duda' => 5, 'aun_no_requerido' => 4, 'no_verificable' => 3, 'ok_en_ruta' => 2, 'ok_almacenaje' => 2, 'ok' => 1,
    ];

    /**
     * @param array<string,mixed> $data data_json de la carga (lugares.{inicio|cliente|final}[])
     * @param array{status:string,orden:?array,unidad:?array}|null $order resultado de buscar_ordenes (null = sin respuesta)
     * @param array{status:string,tramos:list<array>}|null $legs resultado de tramos_de_ordenes
     * @param array<string,string> $needCfg cruce.cuando_hace_falta
     * @return array{skip:bool,state:string,done:bool,order_id:?string,points:array<string,string>,alerts:list<array<string,mixed>>,contradicted:list<string>}
     */
    public static function evaluate(array $data, ?array $order, ?array $legs, array $needCfg = [], ?int $now = null): array
    {
        $out = ['skip' => false, 'state' => 'no_verificable', 'done' => true, 'order_id' => null, 'points' => [], 'alerts' => [], 'contradicted' => []];

        if ($order === null || $order['status'] === 'error') {
            $out['skip'] = true; // sin respuesta del sistema: la carga queda igual para la próxima corrida y no se avisa

            return $out;
        }
        if ($order['status'] === 'no_encontrado' || $order['orden'] === null) {
            $out['state'] = 'falta_en_sistema';
            $out['points']['carga'] = 'no_encontrada';
            $out['alerts'][] = ['decision' => 'cruce_sin_orden', 'kind' => 'falta_en_sistema', 'point' => null, 'mail_value' => 'instrucción recibida',
                'system_value' => 'sin orden en el sistema', 'source' => '', 'executed' => false];

            return $out;
        }
        if ($legs !== null && $legs['status'] === 'error') {
            $out['skip'] = true;

            return $out;
        }

        $out['order_id'] = (string) ($order['orden']['service_id'] ?? '') ?: null;
        $unit = $order['unidad'] ?? [];
        $tramos = $legs['tramos'] ?? [];
        $pending = false;

        foreach (['inicio', 'cliente', 'final'] as $point) {
            $mentions = $data['lugares'][$point] ?? [];
            if ($mentions === []) {
                continue;
            }
            $names = [];
            $zones = [];
            $brands = [];
            $waiting = false;
            $source = '';
            $firstText = '';
            foreach ($mentions as $m) {
                $source = $source !== '' ? $source : (string) ($m['fuente'] ?? '');
                $firstText = $firstText !== '' ? $firstText : (string) ($m['texto'] ?? '');
                $method = (string) ($m['metodo'] ?? '');
                $res = $m['resuelto'] ?? null;
                if ($method === 'pendiente' || $res === null) {
                    $waiting = $waiting || $method === 'pendiente';
                } elseif ($method === 'generico') {
                    $zones[] = (string) $res;
                } elseif ($method === 'marca') {
                    $brands[] = (string) ($m['marca'] ?? $res);
                } else {
                    $names[] = (string) $res;
                }
            }
            if ($names === [] && $zones === [] && $brands === []) {
                $out['points'][$point] = $waiting ? 'esperando_duda' : 'no_verificable';
                $pending = $pending || $waiting;
                continue;
            }
            $label = implode(' o ', array_merge($names, $zones, array_map(static fn (string $b): string => 'alguna sede de ' . $b, $brands))) ?: $firstText;
            $sysValue = trim((string) ($unit[self::SYSTEM_FIELD[$point]] ?? ''));

            if ($sysValue === '') {
                if (!NeedRules::needed($point, $tramos, $needCfg)) {
                    $out['points'][$point] = 'aun_no_requerido';
                    $pending = true;
                    continue;
                }
                $out['points'][$point] = 'lugar_vacio';
                $out['alerts'][] = ['decision' => 'cruce_lugar_vacio', 'kind' => 'lugar_vacio', 'point' => $point, 'mail_value' => $label,
                    'system_value' => '(vacío)', 'source' => $source, 'executed' => false];
                continue;
            }

            $cmp = PlaceComparer::compare($names, $zones, $brands, $sysValue);
            if ($cmp === PlaceComparer::SAME) {
                $out['points'][$point] = 'ok';
                continue;
            }
            if ($cmp === PlaceComparer::UNKNOWN) {
                $out['points'][$point] = 'no_verificable';
                continue;
            }
            // Un lugar del correo presente en cualquier tramo del contenedor: ruta con almacenaje intermedio.
            if ($point !== 'cliente' && self::inAnyLeg($names, $tramos)) {
                $out['points'][$point] = 'ok_en_ruta';
                continue;
            }
            // Patio propio: el contenedor sigue en el sistema bajo el lugar actual.
            $current = trim((string) ($unit['current_name'] ?? ''));
            if ($point === 'inicio' && $current !== '' && PlaceComparer::compare($names, [], [], $current) === PlaceComparer::SAME) {
                $out['points'][$point] = 'ok_almacenaje';
                continue;
            }
            if ($waiting) {
                $out['points'][$point] = 'esperando_duda';
                $pending = true;
                continue;
            }
            $executed = NeedRules::executed($point, $tramos, $now);
            $out['points'][$point] = $executed ? 'distinto_ejecutado' : 'distinto';
            $out['alerts'][] = ['decision' => $executed ? 'cruce_distinto_ejecutado' : 'cruce_distinto', 'kind' => 'distinto', 'point' => $point,
                'mail_value' => $label, 'system_value' => trim((string) (preg_split('/\s+-\s+/u', $sysValue)[0] ?? $sysValue)),
                'source' => $source, 'executed' => $executed];
            if (!$executed) {
                // El sistema contradice lo que se resolvió: las equivalencias usadas pierden salud. Un cambio de plan ya ejecutado no cuenta.
                foreach ($mentions as $m) {
                    if (in_array((string) ($m['metodo'] ?? ''), ['equivalencia', 'exacto'], true) && ($m['texto'] ?? '') !== '') {
                        $out['contradicted'][] = Places::normalize((string) $m['texto']);
                    }
                }
            }
        }

        $out['state'] = self::summarize($out['points']);
        // Se sigue cruzando mientras algo pueda cambiar dentro de la ventana (falta de dato aún no requerido, duda abierta).
        $out['done'] = !$pending;

        return $out;
    }

    /** @param array<string,string> $points */
    public static function summarize(array $points): string
    {
        $best = 'ok';
        $bestW = 0;
        foreach ($points as $s) {
            $w = self::SEVERITY[$s] ?? 0;
            if ($w > $bestW) {
                $best = $s === 'no_encontrada' ? 'falta_en_sistema' : $s;
                $bestW = $w;
            }
        }

        return $points === [] ? 'no_verificable' : $best;
    }

    /** @param list<string> $names @param list<array{from_name:string,to_name:string}> $legs */
    private static function inAnyLeg(array $names, array $legs): bool
    {
        foreach ($legs as $l) {
            foreach ([$l['from_name'], $l['to_name']] as $v) {
                if ($v !== '' && PlaceComparer::compare($names, [], [], $v) === PlaceComparer::SAME) {
                    return true;
                }
            }
        }

        return false;
    }
}
