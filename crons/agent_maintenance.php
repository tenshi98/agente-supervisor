<?php

declare(strict_types=1);

/**
 * Mantenimiento (sin IA). Frecuencia sugerida: cada 30 a 60 minutos.
 *   php /ruta/www/agente/crons/agent_maintenance.php
 *
 * Por tenant activo:
 *   - Reabre las dudas cuyo enlace venció sin respuesta (se reenvían con enlace nuevo en la próxima ingesta).
 *   - Retención: vacía cuerpo y texto de adjuntos de correos resueltos pasados `retention_days` días (queda lo decidido).
 *   - Borra los originales guardados de adjuntos ilegibles de correos ya resueltos.
 *   - Salud de equivalencias: informa las que el sistema externo contradijo y esperan revisión.
 */

use Agente\Persistence\Aliases;
use Agente\Persistence\Messages;
use Agente\Persistence\Questions;
use Agente\Support\CronRunner;
use Agente\Support\Services;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(CronRunner::run(dirname(__DIR__), 'agent_maintenance', static function (Services $svc, array $t): array {
    $tid = (int) $t['tenant_id'];
    $messages = new Messages($svc->db);
    $retention = (int) ($t['retention_days'] ?? 0) ?: (int) $svc->rules->get('retencion.dias_defecto', 90);

    return [
        'dudas_reabiertas' => (new Questions($svc->db))->reopenExpired($tid, (int) $t['link_ttl_hours']),
        'originales_borrados' => $messages->purgeOriginals($tid, $svc->config->int('ATTACH_KEEP_DAYS', 7)),
        'correos_vaciados' => $messages->purgeContent($tid, $retention),
        'equivalencias_por_revisar' => count((new Aliases($svc->db))->needingReview($tid)),
    ];
}));
