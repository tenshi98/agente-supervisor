<?php

declare(strict_types=1);

/** Resumen del estado del agente: correos, dudas, cargas y cruces, avisos, equivalencias, consultas MCP, gasto de IA y últimas corridas. */

use Agente\Support\Db;

$config = require __DIR__ . '/bootstrap.php';
$db = Db::agent($config);
$one = static fn (string $sql): array => $db->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);

echo "Correos por estado:\n";
foreach ($one('SELECT status, COUNT(*) FROM ndb_agent_messages GROUP BY status') as $k => $v) {
    echo "  {$k}: {$v}\n";
}
echo "Dudas por estado:\n";
foreach ($one('SELECT status, COUNT(*) FROM ndb_agent_questions GROUP BY status') as $k => $v) {
    echo "  {$k}: {$v}\n";
}
echo "Cargas por estado:\n";
foreach ($one('SELECT state, COUNT(*) FROM ndb_agent_cases GROUP BY state') as $k => $v) {
    echo "  {$k}: {$v}\n";
}
echo "Cruces (último resultado por carga):\n";
foreach ($one("SELECT cross_state, COUNT(*) FROM ndb_agent_cases WHERE cross_state IS NOT NULL GROUP BY cross_state") as $k => $v) {
    echo "  {$k}: {$v}\n";
}
echo "Avisos por estado:\n";
foreach ($one('SELECT status, COUNT(*) FROM ndb_agent_alerts GROUP BY status') as $k => $v) {
    echo "  {$k}: {$v}\n";
}
$fb = $db->query('SELECT COALESCE(SUM(feedback = 1),0), COALESCE(SUM(feedback = 0),0) FROM ndb_agent_alerts')->fetch(PDO::FETCH_NUM);
echo "Avisos con ✓ / ✗: {$fb[0]} / {$fb[1]}\n";
echo 'Equivalencias activas: ' . $db->query('SELECT COUNT(*) FROM ndb_agent_aliases WHERE active = 1')->fetchColumn()
    . ' (por revisar: ' . $db->query('SELECT COUNT(*) FROM ndb_agent_aliases WHERE active = 1 AND needs_review = 1')->fetchColumn() . ")\n";
echo "Consultas MCP hoy (llamadas / elementos):\n";
foreach ($db->query('SELECT tool, COUNT(*) c, SUM(items) i, SUM(error IS NOT NULL) e FROM ndb_agent_mcp_log WHERE created >= UTC_DATE() GROUP BY tool')->fetchAll() as $r) {
    echo sprintf("  %s: %d / %d%s\n", $r['tool'], $r['c'], $r['i'], (int) $r['e'] > 0 ? " ({$r['e']} con error)" : '');
}
echo 'Destinatarios activos: ';
echo implode(', ', array_map(static fn (array $r): string => "{$r['role']}={$r['n']}", $db->query('SELECT role, COUNT(*) n FROM ndb_agent_recipients WHERE active = 1 GROUP BY role')->fetchAll())) . "\n";
echo 'Gasto de IA hoy (US$): ' . number_format((float) $db->query('SELECT COALESCE(SUM(cost_usd),0) FROM ndb_agent_usage WHERE created >= UTC_DATE()')->fetchColumn(), 4) . "\n";
echo "Casillas:\n";
foreach ($db->query('SELECT mailbox_id, email, source_type, active, last_ok, last_error FROM ndb_agent_mailboxes')->fetchAll() as $m) {
    echo sprintf("  #%d %s (%s) activa=%d ok=%s %s\n", $m['mailbox_id'], $m['email'], $m['source_type'], $m['active'], $m['last_ok'] ?? '-', $m['last_error'] ? 'ERROR: ' . $m['last_error'] : '');
}
echo "Últimas corridas:\n";
foreach ($db->query('SELECT run_id, started, finished, error, stats_json FROM ndb_agent_runs ORDER BY run_id DESC LIMIT 5')->fetchAll() as $r) {
    echo sprintf("  #%d %s → %s %s\n    %s\n", $r['run_id'], $r['started'], $r['finished'] ?? '(en curso)', $r['error'] ? 'ERROR: ' . $r['error'] : '', $r['stats_json'] ?? '');
}
