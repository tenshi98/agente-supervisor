<?php

declare(strict_types=1);

namespace Agente\Check;

use Agente\Text\Places;

/**
 * Compara un lugar del correo con el texto que guarda el sistema externo («<nombre> - <dirección>»).
 * Lógica pura. Los duplicados del catálogo por distancia los decide el servidor MCP, no esta clase.
 */
final class PlaceComparer
{
    public const SAME = 'igual';
    public const DIFFERENT = 'distinto';
    /** No hay forma segura de saberlo (genérico sin coincidencia de texto, marca sin sede conocida): no se alerta. */
    public const UNKNOWN = 'no_verificable';

    /** Parte del nombre antes de « - », «,» o «(» (el resto es dirección). */
    public static function head(string $systemValue): string
    {
        $h = preg_split('/\s+-\s+|,|\(/u', $systemValue, 2);

        return Places::normalize((string) ($h[0] ?? ''));
    }

    /** ¿Mismo lugar? Igual normalizado, o uno contenido en el otro por palabras completas, o parecido muy alto. */
    public static function same(string $mail, string $system, int $minSimilarity = 92): bool
    {
        $a = Places::normalize($mail);
        $full = Places::normalize($system);
        $h = self::head($system);
        if ($a === '' || $full === '') {
            return false;
        }
        if ($a === $full || $a === $h) {
            return true;
        }
        foreach ([$h, $full] as $b) {
            if ($b !== '' && (self::containsWords($b, $a) || self::containsWords($a, $b))) {
                return true;
            }
        }

        return Places::similarity($mail, $h !== '' ? $h : $system) >= $minSimilarity;
    }

    /**
     * Compara todas las formas con que el correo dice el lugar contra el valor del sistema.
     *
     * @param list<string> $names lugares resueltos del correo
     * @param list<string> $zonesWords textos de zona genéricos («san antonio»): coinciden si el sistema los contiene
     * @param list<string> $brands marcas (clientes con varias sedes)
     */
    public static function compare(array $names, array $zonesWords, array $brands, string $systemValue): string
    {
        foreach ($names as $n) {
            if (self::same($n, $systemValue)) {
                return self::SAME;
            }
        }
        $full = Places::normalize($systemValue);
        foreach ($zonesWords as $z) {
            if (self::containsWords($full, Places::normalize($z))) {
                return self::SAME;
            }
        }
        foreach ($brands as $b) {
            if (self::containsWords($full, Places::normalize($b))) {
                return self::SAME;
            }
        }
        if ($names === []) {
            return self::UNKNOWN; // solo genérico o marca, sin coincidencia de texto
        }

        return self::DIFFERENT;
    }

    private static function containsWords(string $haystack, string $needle): bool
    {
        return $needle !== '' && str_contains(' ' . $haystack . ' ', ' ' . $needle . ' ');
    }
}
