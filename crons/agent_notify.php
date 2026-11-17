<?php

declare(strict_types=1);

/**
 * Resumen de avisos por hora (SIN IA). Frecuencia sugerida: cada hora.
 * Revalida los avisos pendientes contra el sistema externo justo antes de enviar (una llamada MCP); los que operaciones
 * ya cargó se descartan en silencio.
 */

use Agente\Notify\AlertNotifier;
use Agente\Persistence\Alerts;
use Agente\Persistence\Outbox;
use Agente\Persistence\Recipients;
use Agente\Support\CronRunner;
use Agente\Support\Services;
use Agente\Support\SignedToken;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(CronRunner::run(dirname(__DIR__), 'agent_notify', static function (Services $svc, array $t): array {
    $db = $svc->db;
    $notifier = new AlertNotifier(
        new Alerts($db), new Recipients($db), new Outbox($db), $svc->mailer($t), $svc->orders(), $svc->templates($t),
        new SignedToken($svc->config->require('AGENT_LINK_SECRET'), 'alert-feedback'), $svc->log, $svc->config->require('AGENT_BASE_URL'),
    );

    return $notifier->send((int) $t['tenant_id'], $t, 'agent_notify');
}));
