<?php

declare(strict_types=1);

namespace Agente\Understanding;

use Agente\Attachments\Iso6346;
use Agente\Rules\RuleSet;
use Agente\Text\Places;

/**
 * Última barrera antes de gastar tokens: un correo sin ninguna señal de transporte no llega a la IA.
 * Señales: contenedor ISO válido, booking o despacho (ya detectados en los adjuntos), o una palabra clave.
 */
final class Prefilter
{
    /** @param list<array<string,mixed>> $attachments filas de ndb_agent_attachments */
    public static function hasSignal(string $subject, string $body, array $attachments, RuleSet $rules): bool
    {
        if (!$rules->get('prefiltro.exigir_senal', true)) {
            return true;
        }
        foreach ($attachments as $a) {
            $v = json_decode((string) ($a['validation_json'] ?? ''), true);
            if (is_array($v) && (($v['containers'] ?? []) !== [] || ($v['bookings'] ?? []) !== [] || ($v['dispatches'] ?? []) !== [])) {
                return true;
            }
        }
        $all = $subject . "\n" . $body;
        foreach ($attachments as $a) {
            $all .= "\n" . (string) ($a['text_extracted'] ?? '');
        }
        if (Iso6346::findValid($all) !== []) {
            return true;
        }
        $norm = ' ' . Places::normalize($all) . ' ';
        foreach ((array) $rules->get('prefiltro.palabras_clave', []) as $w) {
            $w = Places::normalize((string) $w);
            if ($w !== '' && str_contains($norm, ' ' . $w . ' ')) {
                return true;
            }
        }

        return false;
    }
}
