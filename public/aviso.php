<?php

declare(strict_types=1);

/**
 * Feedback ✓/✗ de un aviso (mide la calidad de lo que avisa el agente).
 *   GET  ?t=<token>  → pantalla de confirmación (un GET no registra nada: evita votos por vistas previas de correo).
 *   POST ?t=<token>  → registra el voto.
 * Token: Support\SignedToken con propósito «alert-feedback» y claims {a: alert_id, t: tenant_id, r: recipient_id, v: 1|0}.
 * Un solo uso: Alerts::setFeedback solo escribe si el aviso aún no tiene voto.
 * Seguridad como public/index.php: cabeceras, CSP estricta, sin JavaScript, verificación de origen, todo escapado.
 */

use Agente\Persistence\Alerts;
use Agente\Support\Config;
use Agente\Support\Db;
use Agente\Support\Logger;
use Agente\Support\SignedToken;

require dirname(__DIR__) . '/vendor/autoload.php';

$base = dirname(__DIR__);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$page = static function (int $status, string $title, string $body) use ($e): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $e($title) . '</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:560px;margin:2rem auto;padding:0 1rem;color:#222}'
        . 'button{background:#ffab23;border:0;border-radius:6px;padding:.7rem 1.4rem;font:inherit;font-weight:700;cursor:pointer}.mut{color:#666;font-size:.9rem}'
        . '</style><h1>' . $e($title) . '</h1>' . $body . '</html>';
    exit;
};

$token = (string) ($_GET['t'] ?? '');
if ($token === '') {
    $page(404, 'No encontrado', '<p>Esta página solo se usa con un enlace enviado por correo.</p>');
}

try {
    $config = Config::load($base);
    $log = new Logger($base . '/logs/web.log');
    $claims = (new SignedToken($config->require('AGENT_LINK_SECRET'), 'alert-feedback'))->verify($token);
    if ($claims === null || !isset($claims['a'], $claims['t'], $claims['r'], $claims['v'])) {
        $page(403, 'Enlace inválido', '<p>El enlace no es válido o ya venció.</p>');
    }
    $alertId = (int) $claims['a'];
    $tenant = (int) $claims['t'];
    $recipientId = (int) $claims['r'];
    $value = (int) $claims['v'] === 1 ? 1 : 0;

    $db = Db::agent($config);
    // Solo vota quien es un destinatario activo del tenant.
    $st = $db->prepare('SELECT recipient_id FROM ndb_agent_recipients WHERE recipient_id = ? AND tenant_id = ? AND active = 1');
    $st->execute([$recipientId, $tenant]);
    if ($st->fetchColumn() === false) {
        $log->warn("Feedback con destinatario no autorizado: {$recipientId}");
        $page(403, 'Sin autorización', '<p>Tu usuario no está autorizado.</p>');
    }

    $label = $value === 1 ? '✓ El aviso fue útil' : '✗ El aviso no fue útil';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && (parse_url($origin, PHP_URL_HOST) ?? '') !== explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]) {
            $page(403, 'Solicitud rechazada', '<p>El formulario debe enviarse desde esta misma página.</p>');
        }
        if (!(new Alerts($db))->setFeedback($alertId, $value, $recipientId)) {
            $page(410, 'Ya registrado', '<p>Este aviso ya tiene una valoración.</p>');
        }
        $log->info("Aviso {$alertId}: feedback {$value} de {$recipientId}");
        $page(200, 'Gracias', '<p>Registré tu valoración. Sirve para medir la calidad de los avisos.</p>');
    }

    $page(200, 'Valorar aviso', '<p>' . $e($label) . '</p><form method="post" action="?t=' . $e(rawurlencode($token)) . '">'
        . '<p><button type="submit">Confirmar</button></p></form><p class="mut">Solo se puede valorar una vez.</p>');
} catch (Throwable $ex) {
    (new Logger($base . '/logs/web.log'))->error($ex::class . ': ' . $ex->getMessage());
    $page(500, 'Error', '<p>No se pudo procesar la solicitud.</p>');
}
