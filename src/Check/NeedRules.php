<?php

declare(strict_types=1);

namespace Agente\Check;

/**
 * «Un lugar vacío en el sistema solo alerta cuando ya hace falta» (reglas/decision.json: cruce.cuando_hace_falta)
 * y cuándo un tramo cuenta como ya ejecutado por el camión. Lógica pura.
 */
final class NeedRules
{
    /** Camiones que son un marcador (troncal/externo), no un camión que ejecutó el tramo. */
    public const PLACEHOLDER_TRUCKS = ['EXT0'];

    /**
     * @param list<array{status_id:int,from_name:string,to_name:string,truck_id:string,date_start:string}> $legs
     * @param array<string,string> $cfg cruce.cuando_hace_falta: punto => siempre | si_hay_tramos | si_tramo_hacia_cliente
     */
    public static function needed(string $point, array $legs, array $cfg = []): bool
    {
        $rule = $cfg[$point] ?? match ($point) {
            'inicio' => 'siempre',
            'cliente' => 'si_hay_tramos',
            default => 'si_tramo_hacia_cliente',
        };

        return match ($rule) {
            'siempre' => true,
            'si_hay_tramos' => $legs !== [],
            'si_tramo_hacia_cliente' => self::hasLegFrom($legs, 20),
            default => false,
        };
    }

    /**
     * ¿El camión ya ejecutó el tramo de este punto? Tramos con status ≥ 20 (hacia el cliente) y fecha de inicio pasada,
     * con un camión real. El punto final solo cuenta con tramos ≥ 30.
     *
     * @param list<array{status_id:int,from_name:string,to_name:string,truck_id:string,date_start:string}> $legs
     */
    public static function executed(string $point, array $legs, ?int $now = null): bool
    {
        $min = $point === 'final' ? 30 : 20;
        $now ??= time();
        foreach ($legs as $l) {
            if ($l['status_id'] < $min || in_array(strtoupper(trim($l['truck_id'])), self::PLACEHOLDER_TRUCKS, true) || trim($l['truck_id']) === '') {
                continue;
            }
            $t = $l['date_start'] !== '' ? strtotime($l['date_start']) : false;
            if ($t !== false && $t <= $now) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{status_id:int}> $legs */
    private static function hasLegFrom(array $legs, int $status): bool
    {
        foreach ($legs as $l) {
            if ($l['status_id'] >= $status) {
                return true;
            }
        }

        return false;
    }
}
