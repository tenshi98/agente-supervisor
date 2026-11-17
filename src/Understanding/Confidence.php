<?php

declare(strict_types=1);

namespace Agente\Understanding;

/** Niveles de confianza ordenados: baja < media < alta. */
final class Confidence
{
    public const LEVELS = ['baja' => 0, 'media' => 1, 'alta' => 2];

    public static function normalize(mixed $v): string
    {
        $s = mb_strtolower(trim((string) $v));

        return isset(self::LEVELS[$s]) ? $s : 'baja'; // lo que no se entiende se trata como duda
    }

    public static function atLeast(string $level, string $minimum): bool
    {
        return self::LEVELS[self::normalize($level)] >= self::LEVELS[self::normalize($minimum)];
    }
}
