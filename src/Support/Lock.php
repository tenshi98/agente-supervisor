<?php

declare(strict_types=1);

namespace Agente\Support;

/** Una sola corrida a la vez (flock no bloqueante). */
final class Lock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $file)
    {
    }

    public function acquire(): bool
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $h = fopen($this->file, 'c');
        if ($h === false) {
            return false;
        }
        if (!flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);

            return false;
        }
        ftruncate($h, 0);
        fwrite($h, (string) getmypid());
        $this->handle = $h;

        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
