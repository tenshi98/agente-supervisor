<?php

declare(strict_types=1);

namespace Agente\Attachments;

/** Validación del código de contenedor ISO 6346 (4 letras + 6 dígitos + dígito verificador). */
final class Iso6346
{
    /** Valor de cada letra: empieza en 10 y se salta los múltiplos de 11 (11, 22, 33). */
    private static function letterValue(string $ch): int
    {
        $v = 10;
        for ($c = ord('A'); $c < ord($ch); $c++) {
            $v++;
            if ($v % 11 === 0) {
                $v++;
            }
        }

        return $v;
    }

    public static function isValid(string $code): bool
    {
        $code = strtoupper(preg_replace('/[\s\-]+/', '', $code) ?? '');
        if (preg_match('/^[A-Z]{3}[UJZ]\d{7}$/', $code) !== 1) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $ch = $code[$i];
            $val = ctype_alpha($ch) ? self::letterValue($ch) : (int) $ch;
            $sum += $val * (2 ** $i);
        }
        $check = ($sum % 11) % 10;

        return $check === (int) $code[10];
    }

    /** @return list<string> contenedores con dígito verificador válido, normalizados y sin repetir */
    public static function findValid(string $text): array
    {
        $found = [];
        if (preg_match_all('/\b([A-Za-z]{3}[UJZujz])[\s\-]?(\d{6})[\s\-]?(\d)\b/', $text, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $x) {
                $code = strtoupper($x[1] . $x[2] . $x[3]);
                if (self::isValid($code)) {
                    $found[$code] = true;
                }
            }
        }

        return array_keys($found);
    }
}
