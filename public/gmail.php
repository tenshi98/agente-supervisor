<?php

declare(strict_types=1);

/**
 * Conexión de una casilla Gmail por OAuth. Solo funciona con el enlace firmado que genera tools/gmail_link.php.
 *   GET ?t=<token>            → pantalla con el botón "Conectar con Google".
 *   GET ?code=..&state=<t>    → retorno de Google: canjea el código, comprueba que la cuenta es la esperada,
 *                               guarda el refresh token CIFRADO y activa la casilla. El token nunca se muestra.
 * Seguridad: enlace firmado y con vencimiento, de un solo uso (la BD solo acepta la credencial mientras la casilla
 * no tenga ninguna), la cuenta autorizada debe coincidir con la casilla esperada, sin JavaScript y con CSP estricta.
 */

use Agente\Mail\GmailOAuth;
use Agente\Persistence\Mailboxes;
use Agente\Support\Config;
use Agente\Support\Db;
use Agente\Support\Logger;
use Agente\Support\SignedToken;
use Agente\Support\Vault;

require dirname(__DIR__) . '/vendor/autoload.php';

$base = dirname(__DIR__);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'");

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$page = static function (int $status, string $title, string $body) use ($e): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $e($title) . '</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:640px;margin:2rem auto;padding:0 1rem;color:#222}'
        . '.btn{display:inline-block;background:#ffab23;color:#222;font-weight:700;text-decoration:none;padding:.7rem 1.4rem;border-radius:6px}'
        . '.mut{color:#666;font-size:.9rem}</style><h1>' . $e($title) . '</h1>' . $body . '</html>';
    exit;
};

$log = new Logger($base . '/logs/web.log');

try {
    $config = Config::load($base);
    $signer = new SignedToken($config->require('AGENT_LINK_SECRET'), 'gmail-connect');
    $redirect = $config->get('GOOGLE_REDIRECT_URI') ?? rtrim($config->require('AGENT_BASE_URL'), '/') . '/gmail.php';
    $oauth = new GmailOAuth($config->require('GOOGLE_CLIENT_ID'), $config->require('GOOGLE_CLIENT_SECRET'), $redirect, $config->int('HTTP_TIMEOUT', 30));

    $isCallback = isset($_GET['code']) || isset($_GET['error']);
    $token = (string) ($isCallback ? ($_GET['state'] ?? '') : ($_GET['t'] ?? ''));
    if ($token === '') {
        $page(404, 'No encontrado', '<p>Esta página solo se usa con un enlace de conexión.</p>');
    }
    $claims = $signer->verify($token);
    if ($claims === null || !isset($claims['m'], $claims['t'])) {
        $page(403, 'Enlace inválido', '<p>El enlace no es válido o ya venció. Pide uno nuevo al administrador.</p>');
    }

    $db = Db::agent($config);
    $boxes = new Mailboxes($db);
    $box = $boxes->find((int) $claims['m'], (int) $claims['t']);
    if ($box === null || $box['source_type'] !== 'gmail_api') {
        $page(404, 'Casilla no encontrada', '<p>No existe la casilla asociada a este enlace.</p>');
    }
    if ($box['secret_enc'] !== null) {
        $page(410, 'Enlace ya utilizado', '<p>Esta casilla ya fue conectada. El enlace es de un solo uso.</p>');
    }
    $expected = strtolower((string) $box['email']);

    if (!$isCallback) {
        $url = $oauth->authUrl($token, $expected);
        $page(200, 'Conectar Gmail', '<p>El agente leerá los correos de <b>' . $e($expected) . '</b> con permiso de <b>solo lectura</b>: '
            . 'no puede enviar, borrar ni modificar nada.</p><p>Inicia sesión con esa cuenta y acepta el permiso.</p>'
            . '<p><a class="btn" href="' . $e($url) . '">Conectar con Google</a></p>'
            . '<p class="mut">Puedes revocar el acceso cuando quieras en https://myaccount.google.com/permissions</p>');
    }

    // ---- retorno de Google ----
    if (isset($_GET['error'])) {
        $log->warn('Gmail: autorización rechazada por el usuario o por Google: ' . substr((string) $_GET['error'], 0, 100));
        $page(400, 'Autorización cancelada', '<p>No se concedió el permiso, así que la casilla no se conectó. Puedes abrir el enlace otra vez para reintentar.</p>');
    }
    try {
        $r = $oauth->exchange((string) $_GET['code']);
    } catch (Throwable $ex) {
        $log->error('Gmail OAuth: ' . $ex::class . ': ' . $ex->getMessage());
        // Los mensajes propios son seguros de mostrar; los de librerías externas no.
        $page(400, 'No se pudo conectar', '<p>' . ($ex instanceof RuntimeException && $ex::class === RuntimeException::class
            ? $e($ex->getMessage()) : 'Google no aceptó la autorización. Abre el enlace otra vez para reintentar.') . '</p>');
    }
    if ($r['email'] !== $expected) {
        $log->warn("Gmail OAuth: se autorizó {$r['email']} pero se esperaba {$expected}");
        $page(400, 'Cuenta distinta', '<p>Autorizaste otra cuenta (<b>' . $e($r['email']) . '</b>). Debe ser <b>' . $e($expected)
            . '</b>. No se guardó nada; abre el enlace otra vez y elige la cuenta correcta.</p>');
    }
    $enc = (new Vault($config->require('AGENT_VAULT_KEY')))->encrypt(json_encode(['refresh_token' => $r['refresh_token']], JSON_THROW_ON_ERROR));
    if (!$boxes->completeGmail((int) $claims['m'], (int) $claims['t'], $enc)) {
        $page(410, 'Enlace ya utilizado', '<p>Esta casilla ya fue conectada. El enlace es de un solo uso.</p>');
    }
    $log->info("Gmail conectado: casilla #{$claims['m']} ({$expected})");
    $page(200, 'Casilla conectada', '<p>Listo: <b>' . $e($expected) . '</b> quedó conectada y activa. Ya puedes cerrar esta página.</p>');
} catch (Throwable $ex) {
    $log->error($ex::class . ': ' . $ex->getMessage());
    $page(500, 'Error', '<p>No se pudo procesar la solicitud.</p>');
}
