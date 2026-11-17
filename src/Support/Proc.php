<?php

declare(strict_types=1);

namespace Agente\Support;

/** Ejecuta un comando externo (sin shell) con tiempo máximo. */
final class Proc
{
    /** @param list<string> $cmd @return array{code:int,out:string,err:string,timeout:bool} */
    public static function run(array $cmd, int $timeoutSeconds = 60): array
    {
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return ['code' => -1, 'out' => '', 'err' => 'no se pudo iniciar el proceso', 'timeout' => false];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (!$st['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($p, 9);
                break;
            }
            usleep(50_000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);

        return ['code' => $timedOut ? -1 : $code, 'out' => $out, 'err' => $err, 'timeout' => $timedOut];
    }

    public static function exists(string $binary): bool
    {
        $r = self::run(['sh', '-c', 'command -v ' . escapeshellarg($binary)], 5);

        return $r['code'] === 0 && trim($r['out']) !== '';
    }
}
