<?php

declare(strict_types=1);

namespace Agente\Attachments;

/**
 * Señales que se buscan en el texto de un adjunto para decidir si basta con el texto
 * (ahorra tokens) o si hay que enviar la imagen/PDF a la IA.
 */
final class TextSignals
{
    /**
     * @return array{containers:list<string>,bookings:list<string>,dispatches:list<string>,chars:int,valid:bool,reasons:list<string>}
     */
    public static function analyze(string $text, int $minChars = 80): array
    {
        $containers = Iso6346::findValid($text);

        $bookings = [];
        if (preg_match_all('/\b(?:BK|BOOKING|RESERVA)\W{0,3}([A-Z0-9]{6,14})\b/i', $text, $m) > 0) {
            foreach ($m[1] as $b) {
                $b = strtoupper($b);
                // Debe traer algún dígito para no confundir con palabras
                if (preg_match('/\d/', $b) === 1) {
                    $bookings[$b] = true;
                }
            }
        }

        $dispatches = [];
        if (preg_match_all('/\bI-?(\d{5,7})\b/', $text, $m) > 0) {
            foreach ($m[1] as $d) {
                $dispatches['I-' . $d] = true;
            }
        }

        $chars = mb_strlen(trim($text));
        $reasons = [];
        if ($chars < $minChars) {
            $reasons[] = 'texto_corto';
        }
        if ($containers === [] && $bookings === [] && $dispatches === []) {
            $reasons[] = 'sin_identificador';
        }

        return [
            'containers' => $containers,
            'bookings' => array_keys($bookings),
            'dispatches' => array_keys($dispatches),
            'chars' => $chars,
            'valid' => $reasons === [],
            'reasons' => $reasons,
        ];
    }
}
