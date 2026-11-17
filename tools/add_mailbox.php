<?php

declare(strict_types=1);

/**
 * Registra una casilla de correo para que el agente la lea. Las credenciales se guardan CIFRADAS (AGENT_VAULT_KEY).
 *
 *  IMAP:   php tools/add_mailbox.php --type=imap --email=ops@empresa.cl --host=imap.empresa.cl [--port=993] [--security=ssl]
 *                                    [--user=ops@empresa.cl] [--folders=INBOX] [--allowed=cliente.cl,otro.com] [--discard=avisos@x.cl]
 *                                    (contraseña: variable de entorno MAILBOX_SECRET, o --secret=...)
 *  Gmail:  php tools/add_mailbox.php --type=gmail --email=ops@gmail.com [--folders=INBOX] ...   (MAILBOX_SECRET = refresh token)
 *  Carpeta (pruebas sin credenciales): php tools/add_mailbox.php --type=folder --email=demo@local --dir=/ruta/carpeta
 * Opciones comunes: --tenant=1 --name="Nombre"
 */

use Agente\Support\Db;
use Agente\Support\Vault;

$config = require __DIR__ . '/bootstrap.php';
require __DIR__ . '/args.php';
$a = cli_args($argv);

$type = $a['type'] ?? '';
$email = $a['email'] ?? '';
if (!in_array($type, ['imap', 'gmail', 'folder'], true) || $email === '') {
    cli_fail('Uso: --type=imap|gmail|folder --email=... (ver la cabecera de este archivo)');
}
$tenant = (int) ($a['tenant'] ?? 1);
$secret = getenv('MAILBOX_SECRET') ?: ($a['secret'] ?? '');
$vault = new Vault($config->require('AGENT_VAULT_KEY'));

$sourceType = match ($type) { 'gmail' => 'gmail_api', default => $type };
$authType = match ($type) { 'gmail' => 'oauth_google', 'imap' => 'password', default => 'none' };
$host = null;
$secretEnc = null;
if ($type === 'imap') {
    $host = $a['host'] ?? cli_fail('Falta --host');
    $secret !== '' || cli_fail('Falta la contraseña (MAILBOX_SECRET o --secret)');
    $secretEnc = $vault->encrypt(json_encode(['password' => $secret], JSON_THROW_ON_ERROR));
} elseif ($type === 'gmail') {
    $secret !== '' || cli_fail('Falta el refresh token (MAILBOX_SECRET o --secret)');
    $secretEnc = $vault->encrypt(json_encode(['refresh_token' => $secret], JSON_THROW_ON_ERROR));
} else {
    $host = realpath($a['dir'] ?? '') ?: cli_fail('Falta --dir con una carpeta existente');
}

$filter = null;
if (isset($a['allowed']) || isset($a['discard'])) {
    $split = static fn (string $s): array => array_values(array_filter(array_map('trim', explode(',', $s))));
    $filter = json_encode(['allowed' => $split($a['allowed'] ?? ''), 'discard' => $split($a['discard'] ?? '')], JSON_UNESCAPED_UNICODE);
}

$db = Db::agent($config);
$db->prepare(
    'INSERT INTO ndb_agent_mailboxes (tenant_id, name, email, source_type, host, port, security, auth_type, username, secret_enc, folders, filter_from)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
)->execute([
    $tenant, $a['name'] ?? $email, $email, $sourceType, $host, (int) ($a['port'] ?? 993), $a['security'] ?? 'ssl',
    $authType, $a['user'] ?? $email, $secretEnc, $a['folders'] ?? 'INBOX', $filter,
]);
echo "+ casilla #{$db->lastInsertId()} registrada ({$sourceType}: {$email}) para el tenant {$tenant}\n";
