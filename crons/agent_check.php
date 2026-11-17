<?php

declare(strict_types=1);

/**
 * Cruce con el sistema externo (SIN IA). Frecuencia sugerida: cada 15 minutos.
 * Cruza cada carga decidida pasados `grace_min` minutos (60) y hasta `window_hours` horas (72): busca la orden, compara cada
 * lugar y crea avisos. Toda la consulta va por MCP en lote (2 llamadas por corrida sin importar cuántas cargas).
 */

use Agente\Check\Crosschecker;
use Agente\Persistence\Actions;
use Agente\Persistence\Aliases;
use Agente\Persistence\Alerts;
use Agente\Persistence\Cases;
use Agente\Support\CronRunner;
use Agente\Support\Services;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(CronRunner::run(dirname(__DIR__), 'agent_check', static function (Services $svc, array $t): array {
    $db = $svc->db;
    $checker = new Crosschecker(new Cases($db), new Alerts($db), new Actions($db), new Aliases($db), $svc->orders(), $svc->rules);

    return $checker->run((int) $t['tenant_id'], $t, 'agent_check');
}));
