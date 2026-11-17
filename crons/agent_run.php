<?php

declare(strict_types=1);

/**
 * Orquestador manual: ejecuta en orden los crons del agente, cada uno como proceso aparte (así cada uno usa su propio lock).
 *   php crons/agent_run.php            todos
 *   php crons/agent_run.php ingest check
 * En producción conviene programar cada cron con su propia frecuencia (README, sección «Cómo ejecutar»):
 *   agent_ingest  cada 5-15 min   · usa IA
 *   agent_check   cada 15 min     · sin IA (cruza con el sistema externo: 60 min de gracia, hasta 72 h)
 *   agent_notify  cada hora       · sin IA (resumen de avisos, revalidado antes de enviar)
 *   agent_summary a las 19:00     · sin IA (resumen diario)
 *   agent_maintenance cada 30-60 min · sin IA (dudas vencidas, retención, salud de equivalencias)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$all = ['ingest', 'check', 'notify', 'summary', 'maintenance'];
$want = array_slice($argv, 1) ?: $all;
$exit = 0;
foreach ($want as $name) {
    if (!in_array($name, $all, true)) {
        fwrite(STDERR, "Cron desconocido: {$name} (válidos: " . implode(', ', $all) . ")\n");
        exit(2);
    }
    $code = 0;
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/agent_{$name}.php"), $code);
    $exit = max($exit, $code);
}
exit($exit);
