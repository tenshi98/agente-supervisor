<?php

declare(strict_types=1);

/**
 * Aplica las migraciones SQL versionadas (migrations/*.sql), crea los dos usuarios de BD y
 * otorga permisos:
 *   - DB_AGENT_USER: SELECT, INSERT, UPDATE solo sobre tablas ndb_agent_*
 *   - DB_RO_USER:    SELECT (solo lectura, reservado)
 * Usa DB_ADMIN_USER/DB_ADMIN_PASS, que solo se necesitan para esta herramienta.
 */

use Agente\Support\Db;

$config = require __DIR__ . '/bootstrap.php';
$pdo = Db::admin($config);
$db = $config->require('DB_NAME');

$ident = static function (string $s, string $what): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $s)) {
        fwrite(STDERR, "Nombre inválido para {$what}: {$s}\n");
        exit(1);
    }

    return '`' . $s . '`';
};
$host = $config->get('DB_USER_HOST', '%');
if (!preg_match('/^[A-Za-z0-9_.%-]+$/', (string) $host)) {
    fwrite(STDERR, "DB_USER_HOST inválido\n");
    exit(1);
}

$pdo->exec('CREATE TABLE IF NOT EXISTS `ndb_agent_migrations` (
  `file` VARCHAR(190) NOT NULL, `applied` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`file`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$done = $pdo->query('SELECT file FROM ndb_agent_migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
sort($files);
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $done, true)) {
        echo "= {$name} (ya aplicada)\n";
        continue;
    }
    $sql = (string) file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    foreach (array_filter(array_map('trim', explode(";\n", $sql . "\n"))) as $stmt) {
        $pdo->exec(rtrim($stmt, ';'));
    }
    $pdo->prepare('INSERT INTO ndb_agent_migrations (file) VALUES (?)')->execute([$name]);
    echo "+ {$name} aplicada\n";
}

// Usuarios y permisos
$agentUser = $config->require('DB_AGENT_USER');
$roUser = $config->require('DB_RO_USER');
if ($agentUser === $roUser) {
    fwrite(STDERR, "DB_AGENT_USER y DB_RO_USER deben ser distintos.\n");
    exit(1);
}
$h = $pdo->quote((string) $host);
foreach ([[$agentUser, $config->require('DB_AGENT_PASS')], [$roUser, $config->require('DB_RO_PASS')]] as [$u, $p]) {
    $ident($u, 'usuario');
    $q = $pdo->quote($u);
    $pdo->exec("CREATE USER IF NOT EXISTS {$q}@{$h} IDENTIFIED BY " . $pdo->quote($p));
    $pdo->exec("ALTER USER {$q}@{$h} IDENTIFIED BY " . $pdo->quote($p));
}
$qa = $pdo->quote($agentUser);
$qr = $pdo->quote($roUser);
$pdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM {$qa}@{$h}");
$pdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM {$qr}@{$h}");

$tables = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE 'ndb\\_agent\\_%'");
$tables->execute([$db]);
foreach ($tables->fetchAll(PDO::FETCH_COLUMN) as $t) {
    if ($t === 'ndb_agent_migrations') {
        continue;
    }
    $pdo->exec('GRANT SELECT, INSERT, UPDATE ON ' . $ident($db, 'base') . '.' . $ident((string) $t, 'tabla') . " TO {$qa}@{$h}");
}
$pdo->exec('GRANT SELECT ON ' . $ident($db, 'base') . ".* TO {$qr}@{$h}");
echo "+ usuarios y permisos aplicados ({$agentUser}: ndb_agent_* | {$roUser}: solo lectura)\n";

// Tenant y configuración iniciales (apagado y en dry_run por defecto)
if ((int) $pdo->query('SELECT COUNT(*) FROM ndb_agent_tenants')->fetchColumn() === 0) {
    $pdo->prepare('INSERT INTO ndb_agent_tenants (name, timezone) VALUES (?, ?)')
        ->execute([$config->get('AGENT_TENANT_NAME', 'Agente'), $config->get('AGENT_TIMEZONE', 'America/Santiago')]);
    $tid = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO ndb_agent_config (tenant_id, active, dry_run, last_update) VALUES (?, 0, 1, UTC_TIMESTAMP())')
        ->execute([$tid]);
    echo "+ tenant #{$tid} creado (active=0, dry_run=1)\n";
}
echo "Listo.\n";
