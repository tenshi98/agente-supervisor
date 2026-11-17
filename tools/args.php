<?php

declare(strict_types=1);

/**
 * Lee argumentos --clave=valor (y banderas --clave) de la línea de comandos.
 * @return array<string,string>
 */
function cli_args(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z0-9\-]+)(?:=(.*))?$/i', $a, $m) === 1) {
            $out[strtolower($m[1])] = $m[2] ?? '1';
        }
    }

    return $out;
}

function cli_fail(string $msg): never
{
    fwrite(STDERR, $msg . "\n");
    exit(1);
}
