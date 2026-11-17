<?php

declare(strict_types=1);

namespace Agente\Support;

use Throwable;

/**
 * Esqueleto común de los crons: solo por línea de comandos (o por web con CRON_WEB_ENABLED y clave), un lock por proceso,
 * una fila de ndb_agent_runs por tenant y por corrida, y el manejo de errores. Cada cron solo aporta qué hacer por tenant.
 */
final class CronRunner
{
    /**
     * @param string $process agent_ingest | agent_check | agent_notify | agent_summary | agent_maintenance | run
     * @param callable(Services,array<string,mixed>):array<string,mixed> $perTenant devuelve las estadísticas del tenant
     * @param (callable(Services,array<string,mixed>):bool)|null $due si devuelve false el tenant se salta (p. ej. fuera de hora)
     */
    public static function run(string $base, string $process, callable $perTenant, ?callable $due = null): int
    {
        require_once $base . '/vendor/autoload.php';
        $cli = PHP_SAPI === 'cli';
        if (!$cli) {
            $config = Config::load($base);
            $secret = (string) $config->get('CRON_WEB_SECRET', '');
            if (!$config->bool('CRON_WEB_ENABLED') || strlen($secret) < 32 || !hash_equals($secret, (string) ($_GET['key'] ?? ''))) {
                http_response_code(403);
                exit('Forbidden');
            }
            header('Content-Type: text/plain; charset=utf-8');
            ignore_user_abort(true);
        }

        $lock = new Lock($base . '/storage/' . $process . '.lock');
        $exit = 0;
        $svc = null;
        try {
            $svc = new Services($base, $cli);
            set_time_limit($svc->config->int('CRON_MAX_SECONDS', 600));
            if (!$lock->acquire()) {
                $svc->log->warn("{$process}: otra corrida en curso; salgo.");

                return 0;
            }
            $tenants = $svc->tenants();
            if ($tenants === []) {
                $svc->log->info("{$process}: ningún tenant activo (ndb_agent_config.active = 0).");
            }
            foreach ($tenants as $t) {
                if ($due !== null && !$due($svc, $t)) {
                    continue;
                }
                $tid = (int) $t['tenant_id'];
                $svc->db->prepare('INSERT INTO ndb_agent_runs (tenant_id, process, started) VALUES (?, ?, UTC_TIMESTAMP())')->execute([$tid, $process]);
                $runId = (int) $svc->db->lastInsertId();
                $err = null;
                $stats = [];
                try {
                    $stats = $perTenant($svc, $t);
                    $svc->log->info("{$process} · {$t['name']}: " . json_encode($stats, JSON_UNESCAPED_UNICODE));
                } catch (Throwable $e) {
                    $err = substr($e::class . ': ' . $e->getMessage(), 0, 500);
                    $svc->log->error("{$process} · {$t['name']}: {$err}");
                    $exit = 1;
                }
                $svc->db->prepare('UPDATE ndb_agent_runs SET finished = UTC_TIMESTAMP(), stats_json = ?, error = ? WHERE run_id = ?')
                    ->execute([json_encode($stats, JSON_UNESCAPED_UNICODE), $err, $runId]);
            }
        } catch (Throwable $e) {
            ($svc?->log ?? new Logger($base . '/logs/agent.log', $cli))->error("{$process} abortado: " . $e::class . ': ' . $e->getMessage());
            $exit = 1;
        } finally {
            $lock->release();
        }

        return $exit;
    }
}
