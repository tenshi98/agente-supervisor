<?php

declare(strict_types=1);

/**
 * Panel de lectura del agente: equivalencias (activas / por revisar), avisos con su ✓/✗ y bitácora del día
 * (costo de IA y consultas MCP). Solo lectura (usuario RO de la BD). Enlace de administración firmado con
 * propósito «panel» (tools/panel_link.php); vence solo, no se puede reutilizar para otra cosa.
 */

use Agente\Support\Config;
use Agente\Support\Db;
use Agente\Support\Logger;
use Agente\Support\SignedToken;

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
        . '<title>' . $e($title) . '</title><style>body{font:15px/1.5 system-ui,sans-serif;max-width:1000px;margin:2rem auto;padding:0 1rem;color:#222}'
        . 'table{border-collapse:collapse;width:100%;margin:.5rem 0 1.5rem}th,td{border-bottom:1px solid #ddd;padding:.3rem .5rem;text-align:left;font-size:.9rem}'
        . 'th{background:#f4f5f7}.mut{color:#666;font-size:.9rem}h2{margin-top:2rem}</style><h1>' . $e($title) . '</h1>' . $body . '</html>';
    exit;
};
$table = static function (array $head, array $rows) use ($e): string {
    $h = '<table><tr>' . implode('', array_map(static fn (string $c): string => '<th>' . $e($c) . '</th>', $head)) . '</tr>';
    foreach ($rows as $r) {
        $h .= '<tr>' . implode('', array_map(static fn (mixed $c): string => '<td>' . $e($c) . '</td>', array_values($r))) . '</tr>';
    }

    return $h . ($rows === [] ? '<tr><td colspan="' . count($head) . '" class="mut">Sin datos</td></tr>' : '') . '</table>';
};

try {
    $config = Config::load($base);
    $log = new Logger($base . '/logs/web.log');
    $claims = (new SignedToken($config->require('AGENT_LINK_SECRET'), 'panel'))->verify((string) ($_GET['t'] ?? ''));
    if ($claims === null || !isset($claims['t'])) {
        $page(403, 'Enlace inválido', '<p>El enlace no es válido o ya venció. Genera uno nuevo con tools/panel_link.php.</p>');
    }
    $tenant = (int) $claims['t'];
    $db = Db::readonly($config);
    $q = static function (string $sql) use ($db, $tenant): array {
        $st = $db->prepare($sql);
        $st->execute([$tenant]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    };

    $out = '<p class="mut">Tenant ' . $e($tenant) . ' · solo lectura · horas en UTC</p>';

    $rev = $q('SELECT key_norm AS texto, value AS lugar, contradictions AS contradicciones, health AS salud, source AS origen FROM ndb_agent_aliases
               WHERE tenant_id = ? AND active = 1 AND needs_review = 1 ORDER BY health, alias_id LIMIT 100');
    $out .= '<h2>Equivalencias por revisar</h2>' . $table(['Texto', 'Lugar', 'Contradicciones', 'Salud', 'Origen'], $rev);
    $act = $q('SELECT key_norm, value, source, uses, contradictions, health FROM ndb_agent_aliases
               WHERE tenant_id = ? AND active = 1 ORDER BY uses DESC, alias_id DESC LIMIT 200');
    $out .= '<h2>Equivalencias activas (200 más usadas)</h2>' . $table(['Texto', 'Lugar', 'Origen', 'Usos', 'Contradicciones', 'Salud'], $act);

    $fb = $q('SELECT COALESCE(SUM(feedback = 1),0) ok, COALESCE(SUM(feedback = 0),0) no, COUNT(*) total FROM ndb_agent_alerts WHERE tenant_id = ?');
    $out .= '<h2>Avisos</h2><p>Total ' . $e($fb[0]['total'] ?? 0) . ' · ✓ ' . $e($fb[0]['ok'] ?? 0) . ' · ✗ ' . $e($fb[0]['no'] ?? 0) . '</p>';
    $al = $q("SELECT alert_id, created, kind, point, mail_value, system_value, order_id, status,
                     CASE feedback WHEN 1 THEN '✓' WHEN 0 THEN '✗' ELSE '' END AS fb
                FROM ndb_agent_alerts WHERE tenant_id = ? ORDER BY alert_id DESC LIMIT 100");
    $out .= $table(['#', 'Creado', 'Tipo', 'Punto', 'Correo dice', 'Sistema dice', 'Orden', 'Estado', 'Valoración'], $al);

    $ai = $q("SELECT purpose, COUNT(*) llamadas, SUM(tokens_in) tokens_entrada, SUM(tokens_out) tokens_salida, ROUND(SUM(cost_usd),4) costo_usd
                FROM ndb_agent_usage WHERE tenant_id = ? AND created >= UTC_DATE() GROUP BY purpose");
    $out .= '<h2>Costo de IA hoy</h2>' . $table(['Uso', 'Llamadas', 'Tokens entrada', 'Tokens salida', 'US$'], $ai);
    $mcp = $q('SELECT tool, COUNT(*) llamadas, SUM(items) elementos, SUM(ok_items) ok, SUM(error IS NOT NULL) con_error, ROUND(AVG(ms)) ms_promedio
                 FROM ndb_agent_mcp_log WHERE tenant_id = ? AND created >= UTC_DATE() GROUP BY tool');
    $out .= '<h2>Consultas MCP hoy</h2><p class="mut">Ahorro: pocos llamados con muchos elementos.</p>'
        . $table(['Herramienta', 'Llamadas', 'Elementos', 'OK', 'Con error', 'ms prom.'], $mcp);

    $page(200, 'Panel del agente', $out);
} catch (Throwable $ex) {
    (new Logger($base . '/logs/web.log'))->error($ex::class . ': ' . $ex->getMessage());
    $page(500, 'Error', '<p>No se pudo procesar la solicitud.</p>');
}
