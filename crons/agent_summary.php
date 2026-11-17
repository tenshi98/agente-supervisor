<?php

declare(strict_types=1);

/**
 * Resumen diario (SIN IA). Programar cada hora: el envío solo ocurre a la hora local del tenant (`summary_hour`, 19) y una
 * vez por día. `php crons/agent_summary.php --force` lo envía ahora (prueba manual).
 */

use Agente\Notify\SummaryNotifier;
use Agente\Persistence\Alerts;
use Agente\Persistence\Outbox;
use Agente\Persistence\Recipients;
use Agente\Support\CronRunner;
use Agente\Support\Services;

require dirname(__DIR__) . '/vendor/autoload.php';

$force = in_array('--force', $argv ?? [], true);

exit(CronRunner::run(dirname(__DIR__), 'agent_summary', static function (Services $svc, array $t) use ($force): array {
    $db = $svc->db;
    $notifier = new SummaryNotifier(
        $db, new Alerts($db), new Recipients($db), new Outbox($db), $svc->mailer($t), $svc->templates($t),
        $svc->signer(), $svc->log, $svc->config->require('AGENT_BASE_URL'),
    );

    return $notifier->send((int) $t['tenant_id'], $t, $force);
}));
