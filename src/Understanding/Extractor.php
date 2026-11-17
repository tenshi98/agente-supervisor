<?php

declare(strict_types=1);

namespace Agente\Understanding;

use Agente\Llm\LlmClient;
use Agente\Rules\RuleSet;
use Agente\Support\Logger;
use Agente\Text\TextCleaner;

/**
 * Paso 4: la IA comprende el correo. Prioridad: ahorrar tokens.
 *   Pasada 1 (siempre): solo TEXTO ya limpio y recortado (cuerpo + texto de PDF/OCR de los adjuntos).
 *   Pasada 2 (solo si hace falta): si la IA declara ilegible el contenido o pide un original, se reenvía el texto
 *   junto con ESE original (imagen / páginas del PDF), con tope de originales por correo.
 */
final class Extractor
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly RuleSet $rules,
        private readonly OriginalLoader $originals,
        private readonly Logger $log,
    ) {
    }

    /**
     * @param array<string,mixed> $message fila de ndb_agent_messages
     * @param list<array<string,mixed>> $attachments filas de ndb_agent_attachments
     */
    public function extract(array $message, array $attachments): ExtractResult
    {
        $maxOut = (int) $this->rules->get('limites_ia.max_tokens_salida', 2000);
        $text = $this->textContent($message, $attachments);

        $r1 = $this->llm->complete($this->rules->prompt, [['type' => 'text', 'text' => $text]], $maxOut);
        $calls = [['purpose' => 'extract', 'response' => $r1]];
        $ex = Extraction::fromJson($r1->text);

        $wanted = $this->originalsWanted($ex, $attachments);
        if ($wanted === []) {
            return new ExtractResult($ex, $calls);
        }

        $content = [['type' => 'text', 'text' => $text . "\n\nSe adjuntan los originales de los archivos que no se pudieron leer automáticamente."]];
        foreach ($wanted as $a) {
            foreach ($this->originals->blocks((string) $a['file_path'], (string) $a['filename'], (string) ($a['mime'] ?? '')) as $b) {
                $content[] = $b;
            }
        }
        if (count($content) === 1) {
            return new ExtractResult($ex, $calls); // no se pudo preparar ningún original: se queda con lo entendido
        }
        $this->log->info('Segunda pasada con originales (' . count($wanted) . ') para el correo ' . ($message['message_id'] ?? '?'));
        $r2 = $this->llm->complete($this->rules->prompt, $content, $maxOut);
        $calls[] = ['purpose' => 'extract_visual', 'response' => $r2];

        return new ExtractResult(Extraction::fromJson($r2->text), $calls);
    }

    /** @param array<string,mixed> $message @param list<array<string,mixed>> $attachments */
    public function textContent(array $message, array $attachments): string
    {
        $maxBody = (int) $this->rules->get('limites_ia.max_caracteres_cuerpo', 6000);
        $maxAtt = (int) $this->rules->get('limites_ia.max_caracteres_por_adjunto', 4000);
        $maxAll = (int) $this->rules->get('limites_ia.max_caracteres_total', 16000);

        $out = sprintf(
            "ASUNTO: %s\nDE: %s\nFECHA: %s\n\nCUERPO:\n%s\n",
            (string) ($message['subject'] ?? ''),
            (string) ($message['sender'] ?? ''),
            (string) ($message['received'] ?? ''),
            TextCleaner::clean((string) ($message['body_text'] ?? ''), $maxBody),
        );
        foreach ($attachments as $a) {
            $legible = (int) ($a['valid'] ?? 0) === 1 ? 'texto con datos de transporte' : 'texto dudoso o ilegible';
            $t = TextCleaner::clean((string) ($a['text_extracted'] ?? ''), $maxAtt);
            $out .= sprintf("\nADJUNTO: %s (lectura: %s; %s)\n%s\n", $a['filename'], (string) ($a['read_method'] ?? 'ninguno'), $legible, $t !== '' ? $t : '[sin texto]');
        }

        return TextCleaner::truncate($out, $maxAll);
    }

    /**
     * Adjuntos cuyo original se enviará: los que la IA nombró, o (si dijo "ilegible" sin nombrar) los marcados
     * como no válidos. Solo si se guardó el original.
     *
     * @param list<array<string,mixed>> $attachments @return list<array<string,mixed>>
     */
    private function originalsWanted(Extraction $ex, array $attachments): array
    {
        $max = (int) $this->rules->get('limites_ia.max_originales_por_correo', 3);
        $withFile = array_values(array_filter($attachments, static fn (array $a): bool => !empty($a['file_path'])));
        if ($ex->needOriginal !== []) {
            $names = array_map('mb_strtolower', $ex->needOriginal);
            $sel = array_values(array_filter($withFile, static function (array $a) use ($names): bool {
                $f = mb_strtolower((string) $a['filename']);
                foreach ($names as $n) {
                    if ($f === $n || str_contains($f, $n) || str_contains($n, $f)) {
                        return true;
                    }
                }

                return false;
            }));
        } elseif (!$ex->legible) {
            $sel = $withFile;
        } else {
            $sel = [];
        }

        return array_slice($sel, 0, max(0, $max));
    }
}
