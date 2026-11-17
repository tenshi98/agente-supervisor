<?php

declare(strict_types=1);

/**
 * Prepara la conexión de una casilla Gmail por la web. Crea la casilla INACTIVA y sin credencial, y entrega un enlace
 * firmado de un solo uso para que el DUEÑO de la casilla la autorice (public/gmail.php). Al terminar, el refresh token
 * queda cifrado en la BD y la casilla se activa; el token nunca se muestra.
 *
 *   php tools/gmail_link.php --email=ops@gmail.com [--tenant=1] [--folders=INBOX] [--allowed=cliente.cl] [--discard=avisos@x.cl] [--ttl-min=60]
 *
 * Requiere GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET y que la URL de retorno esté registrada en Google Cloud (se imprime abajo).
 * En --folders van las etiquetas de Gmail a leer (INBOX es la etiqueta del buzón de entrada).
 */

use Agente\Persistence\Mailboxes;
use Agente\Support\Db;
use Agente\Support\SignedToken;

$config = require __DIR__ . '/bootstrap.php';
require __DIR__ . '/args.php';
$a = cli_args($argv);

$email = strtolower(trim($a['email'] ?? ''));
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    cli_fail('Uso: --email=casilla@gmail.com [--tenant=1] [--folders=INBOX] [--ttl-min=60]');
}
$config->require('GOOGLE_CLIENT_ID');
$config->require('GOOGLE_CLIENT_SECRET');
$base = rtrim($config->require('AGENT_BASE_URL'), '/');
$redirect = $config->get('GOOGLE_REDIRECT_URI') ?? $base . '/gmail.php';
$signer = new SignedToken($config->require('AGENT_LINK_SECRET'), 'gmail-connect');

$filter = null;
if (isset($a['allowed']) || isset($a['discard'])) {
    $split = static fn (string $s): array => array_values(array_filter(array_map('trim', explode(',', $s))));
    $filter = json_encode(['allowed' => $split($a['allowed'] ?? ''), 'discard' => $split($a['discard'] ?? '')], JSON_UNESCAPED_UNICODE);
}

$tenant = (int) ($a['tenant'] ?? 1);
$ttl = max(5, (int) ($a['ttl-min'] ?? 60));
$id = (new Mailboxes(Db::agent($config)))->createPendingGmail($tenant, $email, $a['folders'] ?? 'INBOX', $filter);
$token = $signer->issue(['m' => $id, 't' => $tenant], $ttl * 60);

echo "+ casilla #{$id} creada (inactiva, pendiente de autorizar)\n\n";
echo "Entrega este enlace al dueño de {$email} (vale {$ttl} minutos y se usa una sola vez):\n\n";
echo "  {$base}/gmail.php?t={$token}\n\n";
echo "URL de retorno que debe estar registrada en Google Cloud (Credenciales > ID de cliente OAuth > URI de redireccionamiento):\n\n";
echo "  {$redirect}\n";
