<?php

declare(strict_types=1);

namespace Agente\Text;

/**
 * Reduce texto antes de enviarlo a la IA (cada carácter cuesta tokens): quita respuestas citadas, firmas de
 * reenvío, líneas repetidas y espacios sobrantes, y recorta al máximo indicado.
 */
final class TextCleaner
{
    public static function clean(string $text, int $maxChars): string
    {
        $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
        $lines = explode("\n", $text);
        $out = [];
        $seen = [];
        foreach ($lines as $line) {
            $t = trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line);
            if (self::isQuoteBoundary($t)) {
                break; // lo que sigue es el historial citado del hilo
            }
            if ($t !== '' && $t[0] === '>') {
                continue; // línea citada de un correo anterior
            }
            if ($t === '') {
                if ($out !== [] && end($out) !== '') {
                    $out[] = ''; // un solo salto de línea entre párrafos
                }
                continue;
            }
            // Líneas largas idénticas (cintillos legales repetidos) no aportan nada la segunda vez.
            if (mb_strlen($t) > 40) {
                $k = md5(mb_strtolower($t));
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
            }
            $out[] = $t;
        }
        $clean = trim(implode("\n", $out));

        return self::truncate($clean, $maxChars);
    }

    public static function truncate(string $text, int $maxChars): string
    {
        if ($maxChars > 0 && mb_strlen($text) > $maxChars) {
            return rtrim(mb_substr($text, 0, $maxChars)) . "\n[…recortado]";
        }

        return $text;
    }

    private static function isQuoteBoundary(string $line): bool
    {
        if ($line === '') {
            return false;
        }

        return preg_match('/^-{2,}\s*(original message|mensaje original|forwarded message|mensaje reenviado)/i', $line) === 1
            || preg_match('/^(on|el) .{5,120}(wrote|escribi[oó]):\s*$/i', $line) === 1
            || preg_match('/^_{5,}$/', $line) === 1;
    }
}
