<?php

declare(strict_types=1);

namespace Agente\Support;

final class Logger
{
    public function __construct(private readonly string $file, private readonly bool $echo = false)
    {
    }

    public function info(string $msg): void
    {
        $this->write('INFO', $msg);
    }

    public function warn(string $msg): void
    {
        $this->write('WARN', $msg);
    }

    public function error(string $msg): void
    {
        $this->write('ERROR', $msg);
    }

    private function write(string $level, string $msg): void
    {
        $line = sprintf("%s [%s] %s\n", gmdate('Y-m-d H:i:s'), $level, str_replace(["\r", "\n"], ' ', $msg));
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
        if ($this->echo) {
            fwrite(STDOUT, $line);
        }
    }
}
