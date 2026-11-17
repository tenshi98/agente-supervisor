<?php

declare(strict_types=1);

/**
 * Genera un enlace firmado de solo lectura al panel (public/panel.php): equivalencias, avisos con ✓/✗, costos de IA y MCP.
 *   php tools/panel_link.php [--tenant=1] [--ttl-min=60]
 */

use Agente\Support\SignedToken;

$config = require __DIR__ . '/bootstrap.php';
require __DIR__ . '/args.php';
$a = cli_args($argv);

$tenant = (int) ($a['tenant'] ?? 1);
$ttl = max(5, (int) ($a['ttl-min'] ?? 60));
$token = (new SignedToken($config->require('AGENT_LINK_SECRET'), 'panel'))->issue(['t' => $tenant], $ttl * 60);
echo "Panel del tenant {$tenant} (vale {$ttl} minutos, solo lectura):\n\n  " . rtrim($config->require('AGENT_BASE_URL'), '/') . "/panel.php?t={$token}\n";
