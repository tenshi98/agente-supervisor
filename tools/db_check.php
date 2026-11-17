<?php

declare(strict_types=1);

/** Verifica conexión y que los permisos de los dos usuarios son los esperados. */

use Agente\Support\Db;

$config = require __DIR__ . '/bootstrap.php';
$fail = 0;
$check = static function (string $label, bool $ok, string $extra = '') use (&$fail): void {
    echo ($ok ? '[ OK ] ' : '[FAIL] ') . $label . ($extra !== '' ? " — {$extra}" : '') . "\n";
    if (!$ok) {
        $fail++;
    }
};

try {
    $agent = Db::agent($config);
    $ro = Db::readonly($config);
    $check('conexión usuario agente', true);
    $check('conexión usuario solo lectura', true);
} catch (Throwable $e) {
    $check('conexión', false, $e->getMessage());
    exit(1);
}

// Tablas del esquema v2 presentes (y la antigua memoria reemplazada por equivalencias)
$have = $agent->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'ndb\\_agent\\_%'")->fetchAll(PDO::FETCH_COLUMN);
$need = ['aliases', 'alerts', 'mcp_log', 'actions', 'config', 'recipients', 'messages', 'cases', 'questions', 'outbox'];
$missing = array_values(array_filter($need, static fn (string $t): bool => !in_array('ndb_agent_' . $t, $have, true)));
$check('tablas v2 presentes', $missing === [], 'faltan: ' . implode(', ', $missing));
$check('no queda ndb_agent_memory', !in_array('ndb_agent_memory', $have, true), 'tabla antigua: reemplazada por ndb_agent_aliases');

// Permisos del agente: solo SELECT/INSERT/UPDATE sobre ndb_agent_*
$grants = $agent->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
$badAgent = [];
foreach ($grants as $g) {
    if (preg_match('/^GRANT (.+) ON (.+) TO /', (string) $g, $m) !== 1) {
        continue;
    }
    $privs = array_map('trim', explode(',', $m[1]));
    if ($privs === ['USAGE']) {
        continue;
    }
    $okPrivs = array_diff($privs, ['SELECT', 'INSERT', 'UPDATE']) === [];
    $okTable = preg_match('/\.`ndb_agent_[a-z_]+`$/', $m[2]) === 1;
    if (!$okPrivs || !$okTable) {
        $badAgent[] = $g;
    }
}
$check('el agente solo escribe en ndb_agent_*', $badAgent === [], implode(' | ', $badAgent));

// Permisos del usuario RO: solo SELECT
$badRo = [];
foreach ($ro->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $g) {
    if (preg_match('/^GRANT (.+) ON /', (string) $g, $m) === 1 && trim($m[1]) !== 'USAGE' && trim($m[1]) !== 'SELECT') {
        $badRo[] = $g;
    }
}
$check('usuario RO solo tiene SELECT', $badRo === [], implode(' | ', $badRo));

// Prueba funcional de escritura
try {
    $ro->exec("INSERT INTO ndb_agent_runs (process, started) VALUES ('db_check', UTC_TIMESTAMP())");
    $check('usuario RO no puede escribir', false, 'la escritura funcionó');
} catch (Throwable) {
    $check('usuario RO no puede escribir', true);
}
try {
    $agent->beginTransaction();
    $agent->exec("INSERT INTO ndb_agent_runs (process, started) VALUES ('db_check', UTC_TIMESTAMP())");
    $agent->rollBack();
    $check('agente puede escribir en ndb_agent_runs', true);
} catch (Throwable $e) {
    $check('agente puede escribir en ndb_agent_runs', false, $e->getMessage());
}
try {
    $agent->exec('CREATE TABLE zz_db_check_no_permitido (id INT)');
    $agent->exec('DROP TABLE zz_db_check_no_permitido');
    $check('agente no puede crear tablas', false, 'se pudo crear una tabla');
} catch (Throwable) {
    $check('agente no puede crear tablas', true);
}

exit($fail === 0 ? 0 : 1);
