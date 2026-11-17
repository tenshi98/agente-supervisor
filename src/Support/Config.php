<?php

declare(strict_types=1);

namespace Agente\Support;

use RuntimeException;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Lee la configuración exclusivamente desde config/.env (o variables de entorno reales).
 * Nunca hay credenciales en el código.
 */
final class Config
{
    /** @var array<string,string> */
    private array $values;

    /** @param array<string,string> $values */
    public function __construct(array $values, private readonly string $basePath)
    {
        $this->values = $values;
    }

    public static function load(string $basePath, ?string $envFile = null): self
    {
        $envFile ??= $basePath . '/config/.env';
        $values = [];
        if (is_file($envFile)) {
            $values = (new Dotenv())->parse((string) file_get_contents($envFile), $envFile);
        }
        // Las variables de entorno reales tienen prioridad sobre el archivo.
        foreach ($values as $k => $v) {
            $real = getenv($k);
            if ($real !== false) {
                $values[$k] = $real;
            }
        }

        return new self($values, $basePath);
    }

    public function basePath(string $sub = ''): string
    {
        return $sub === '' ? $this->basePath : $this->basePath . '/' . ltrim($sub, '/');
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $v = $this->values[$key] ?? $default;

        return ($v === '' ? $default : $v);
    }

    public function require(string $key): string
    {
        $v = $this->get($key);
        if ($v === null) {
            throw new RuntimeException("Falta la variable de configuración {$key} en config/.env");
        }

        return $v;
    }

    public function int(string $key, int $default): int
    {
        $v = $this->get($key);

        return $v === null ? $default : (int) $v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key);
        if ($v === null) {
            return $default;
        }

        return in_array(strtolower($v), ['1', 'true', 'yes', 'on', 'si', 'sí'], true);
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $v = $this->get($key);
        if ($v === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $v)), static fn (string $s): bool => $s !== ''));
    }
}
