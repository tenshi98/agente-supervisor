<?php

declare(strict_types=1);

/**
 * Enciende/apaga un tenant y ajusta su configuración.
 *   php tools/tenant.php --tenant=1 --active=1 --dry-run=0
 * Opciones: --active=0|1  --dry-run=0|1  --max-run=8  --max-day=30  --model=claude-sonnet-5-5  --ai-max-usd=5  --ttl-hours=72
 *   --grace-min=60  --window-hours=72  --notify-every-min=60  --summary-hour=19  --retention-days=90
 *   --brand-name="Mi empresa"  --brand-footer="Texto del pie de los correos"
 */

use Agente\Support\Db;

$config = require __DIR__ . '/bootstrap.php';
require __DIR__ . '/args.php';
$a = cli_args($argv);

$map = ['active' => 'active', 'dry-run' => 'dry_run', 'max-run' => 'max_questions_run', 'max-day' => 'max_questions_day',
    'model' => 'llm_model', 'ai-max-usd' => 'ai_max_usd_day', 'ttl-hours' => 'link_ttl_hours',
    'grace-min' => 'grace_min', 'window-hours' => 'window_hours', 'notify-every-min' => 'notify_every_min', 'summary-hour' => 'summary_hour',
    'retention-days' => 'retention_days', 'brand-name' => 'brand_name', 'brand-footer' => 'brand_footer'];
$sets = [];
$vals = [];
foreach ($map as $opt => $col) {
    if (isset($a[$opt])) {
        $sets[] = "{$col} = ?";
        $vals[] = $a[$opt];
    }
}
if ($sets === []) {
    cli_fail('Nada que cambiar. Ver la cabecera de este archivo.');
}
$tenant = (int) ($a['tenant'] ?? 1);
$db = Db::agent($config);
$vals[] = $tenant;
$db->prepare('UPDATE ndb_agent_config SET ' . implode(', ', $sets) . ', last_update = UTC_TIMESTAMP() WHERE tenant_id = ?')->execute($vals);
if (isset($a['active'])) {
    $db->prepare('UPDATE ndb_agent_tenants SET active = 1 WHERE tenant_id = ?')->execute([$tenant]);
}
echo "+ tenant {$tenant} actualizado\n";
