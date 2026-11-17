<?php

declare(strict_types=1);

namespace Agente\Text;

/** Normalización y parecido de nombres de lugares escritos a mano en los correos. */
final class Places
{
    private const STOP = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'en', 'a', 'al', 'ltda', 'spa', 'sa', 'chile', 'cl', 'n'];
    private const GENERIC = ['terminal', 'puerto', 'deposito', 'depot', 'bodega', 'planta', 'cd', 'centro', 'distribucion',
        'almacen', 'extraportuario', 'patio', 'contenedores'];

    /** Minúsculas, sin tildes ni signos, espacios simples. */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        if (class_exists(\Normalizer::class)) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($d)) {
                $s = (string) preg_replace('/\p{Mn}+/u', '', $d);
            }
        } else {
            $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        }
        $s = (string) preg_replace('/[^a-z0-9 ]+/', ' ', $s);

        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    /** @return list<string> palabras con significado (sin conectores ni genéricos como "terminal") */
    public static function tokens(string $s): array
    {
        $all = array_values(array_filter(explode(' ', self::normalize($s)), static fn (string $w): bool => $w !== '' && !in_array($w, self::STOP, true)));
        $core = array_values(array_filter($all, static fn (string $w): bool => !in_array($w, self::GENERIC, true)));

        return $core !== [] ? $core : $all;
    }

    /** Parecido 0-100 entre lo escrito en el correo y un nombre conocido. */
    public static function similarity(string $raw, string $target): int
    {
        $a = self::normalize($raw);
        $b = self::normalize($target);
        if ($a === '' || $b === '') {
            return 0;
        }
        if ($a === $b) {
            return 100;
        }
        similar_text($a, $b, $pct);
        $ta = self::tokens($raw);
        $tb = self::tokens($target);
        $inter = count(array_intersect($ta, $tb));
        $union = count(array_unique(array_merge($ta, $tb)));
        $jac = $union > 0 ? $inter / $union : 0.0;
        $cov = $ta !== [] ? $inter / count($ta) : 0.0;
        $extra = count(array_diff($tb, $ta));
        $covAdj = $cov * max(0.5, 1 - 0.12 * $extra); // "DPW" no debe calzar fuerte con "DPW LOS MORROS"

        return (int) round(max($pct, 100 * $jac, 95 * $covAdj));
    }
}
