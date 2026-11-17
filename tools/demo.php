<?php

declare(strict_types=1);

/**
 * Prepara una demostración sin credenciales: crea 3 correos de ejemplo en storage/demo_inbox/, registra una casilla
 * de tipo 'folder' que los lee y un usuario autorizado de prueba.
 *   php tools/demo.php [--tenant=1] [--recipient=jefe@ejemplo.cl]
 * Luego: php tools/tenant.php --active=1   y   php crons/agent_run.php
 * (Para que la IA lea los correos hace falta ANTHROPIC_API_KEY; sin ella quedan en cola.)
 * Las vistas previas de las dudas quedan en storage/outbox/ (con dry_run=1 no sale ningún correo).
 *
 * Sin servidor MCP real: la demo escribe storage/demo_mcp.json en el formato de src/Mcp/FixtureMcp.php (catálogo y una orden
 * con el contenedor de la demo y el lugar de retiro vacío, para que el cruce genere un aviso). Con MCP_URL=fake el cableado
 * de los crons debe usar FixtureMcp::fromFile(storage/demo_mcp.json).
 */

use Agente\Attachments\Iso6346;
use Agente\Support\Db;

$config = require __DIR__ . '/bootstrap.php';
require __DIR__ . '/args.php';
$a = cli_args($argv);
$tenant = (int) ($a['tenant'] ?? 1);
$recipient = $a['recipient'] ?? 'jefe@ejemplo.cl';

// Contenedor con dígito verificador válido
$container = '';
for ($d = 0; $d <= 9; $d++) {
    if (Iso6346::isValid('TEMU926016' . $d)) {
        $container = 'TEMU926016' . $d;
        break;
    }
}

$dir = $config->basePath('storage/demo_inbox');
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$now = time();
$mails = [
    'instruccion.json' => [
        'message_id' => '<demo-1@ejemplo.local>', 'from' => 'operaciones@cliente-ejemplo.cl', 'from_name' => 'Operaciones',
        'subject' => "Instrucción de retiro contenedor {$container}", 'date' => gmdate('Y-m-d H:i:s', $now - 600),
        'body' => "Buenos días,\n\nFavor retirar el contenedor {$container} desde Terminal Pto Central el 2026-11-20 a las 08:00, "
            . "entregar en Frutícola Los Andes, Camino a Melipilla km 12, y devolver el vacío en Depósito Norte Maipú.\n\nSaludos.",
        'attachments' => [],
    ],
    'aviso.json' => [
        'message_id' => '<demo-2@ejemplo.local>', 'from' => 'reportes@cliente-ejemplo.cl', 'from_name' => 'Reportes',
        'subject' => 'Reporte de stock semanal (contenedores en patio)', 'date' => gmdate('Y-m-d H:i:s', $now - 500),
        'body' => 'Adjuntamos el stock semanal de contenedores en patio. No requiere acción.', 'attachments' => [],
    ],
    'sin_senal.json' => [
        'message_id' => '<demo-3@ejemplo.local>', 'from' => 'ana@cliente-ejemplo.cl', 'from_name' => 'Ana',
        'subject' => 'Gracias', 'date' => gmdate('Y-m-d H:i:s', $now - 400), 'body' => 'Muchas gracias por todo. Saludos.', 'attachments' => [],
    ],
];
foreach ($mails as $file => $m) {
    file_put_contents($dir . '/' . $file, json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
echo "+ 3 correos de ejemplo en {$dir}\n";

$fixture = [
    'genericos' => ['san antonio', 'valparaiso', 'santiago'],
    'catalogo' => [
        ['place_id' => 'P1', 'nombre' => 'TERMINAL PUERTO CENTRAL', 'direccion' => 'San Antonio', 'comuna' => 'San Antonio', 'categoria' => 'puerto', 'activo' => true, 'marca' => null],
        ['place_id' => 'D1', 'nombre' => 'DEPOSITO NORTE MAIPU', 'direccion' => 'Maipú', 'comuna' => 'Maipú', 'categoria' => 'deposito', 'activo' => true, 'marca' => null],
        ['place_id' => 'D2', 'nombre' => 'DEPOSITO NORTE QUILICURA', 'direccion' => 'Quilicura', 'comuna' => 'Quilicura', 'categoria' => 'deposito', 'activo' => true, 'marca' => null],
    ],
    'ordenes' => [[
        'service_id' => 'S1', 'tipo' => 'I', 'shipment' => 'I-0001', 'reference' => '', 'contenedor' => $container,
        'unidad' => ['start_name' => '', 'delivery_name' => '', 'end_name' => '', 'current_name' => '', 'stacking' => false, 'lcl_type' => ''],
        'tramos' => [],
    ]],
];
file_put_contents($config->basePath('storage/demo_mcp.json'), json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "+ datos MCP simulados en storage/demo_mcp.json\n";

$db = Db::agent($config);
$st = $db->prepare("SELECT COUNT(*) FROM ndb_agent_mailboxes WHERE tenant_id = ? AND source_type = 'folder' AND host = ?");
$st->execute([$tenant, $dir]);
if ((int) $st->fetchColumn() === 0) {
    $db->prepare("INSERT INTO ndb_agent_mailboxes (tenant_id, name, email, source_type, host, auth_type, username, folders) VALUES (?, 'Demo (carpeta)', 'demo@local', 'folder', ?, 'none', 'demo@local', 'INBOX')")
        ->execute([$tenant, $dir]);
    echo "+ casilla de carpeta registrada\n";
}
$st = $db->prepare('SELECT COUNT(*) FROM ndb_agent_recipients WHERE tenant_id = ? AND email = ?');
$st->execute([$tenant, $recipient]);
if ((int) $st->fetchColumn() === 0) {
    $ins = $db->prepare("INSERT INTO ndb_agent_recipients (tenant_id, email, name, role) VALUES (?, ?, 'Usuario de prueba', ?)");
    foreach (['duda', 'aviso', 'resumen'] as $role) {
        $ins->execute([$tenant, $recipient, $role]);
    }
    echo "+ destinatario de prueba con roles duda, aviso y resumen: {$recipient}\n";
}
echo "Siguiente: php tools/seed_equivalences.php --tenant={$tenant}; php tools/tenant.php --tenant={$tenant} --active=1 && MCP_URL=fake php crons/agent_ingest.php\n";
