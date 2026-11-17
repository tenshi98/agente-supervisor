<?php

declare(strict_types=1);

/**
 * Registra un destinatario del agente. Cada correo puede tener varios roles:
 *   duda    decide (recibe las dudas al momento y puede responderlas)
 *   aviso   recibe el resumen por hora
 *   resumen recibe el resumen diario
 *   php tools/add_recipient.php --email=jefe@empresa.cl [--name="Jefe de operaciones"] [--role=duda,aviso,resumen] [--tenant=1]
 * Solo los correos de esta tabla reciben mensajes del agente (nunca se escribe a quien aparece en un correo).
 */

use Agente\Persistence\Recipients;
use Agente\Support\Db;

$config = require __DIR__ . '/bootstrap.php';
require __DIR__ . '/args.php';
$a = cli_args($argv);

$email = trim($a['email'] ?? '');
$roles = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($a['role'] ?? 'duda'))))));
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $roles === [] || array_diff($roles, Recipients::ROLES) !== []) {
    cli_fail('Uso: --email=correo@valido [--name="Nombre"] [--role=duda,aviso,resumen] [--tenant=1]');
}
$tenant = (int) ($a['tenant'] ?? 1);
$db = Db::agent($config);
$st = $db->prepare('INSERT INTO ndb_agent_recipients (tenant_id, email, name, role) VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE name = COALESCE(VALUES(name), name), active = 1');
foreach ($roles as $role) {
    $st->execute([$tenant, $email, $a['name'] ?? null, $role]);
    echo "+ destinatario {$email} con rol {$role}\n";
}
