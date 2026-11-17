<?php

declare(strict_types=1);

namespace Agente\Mail;

/**
 * Filtro previo a la IA: lo que no pasa no gasta tokens.
 * Coincide por correo exacto o por dominio (también subdominios).
 */
final class MailFilter
{
    /** @var list<string> */
    private array $allowed;
    /** @var list<string> */
    private array $discard;

    /**
     * @param list<string> $allowed lista vacía = se acepta cualquier remitente
     * @param list<string> $discard avisos automáticos que se descartan sin IA
     */
    public function __construct(array $allowed, array $discard)
    {
        $this->allowed = array_map('strtolower', $allowed);
        $this->discard = array_map('strtolower', $discard);
    }

    /** @return ?string motivo de descarte o null si pasa */
    public function reason(string $fromAddress, string $subject = ''): ?string
    {
        // La respuesta a una duda («[Duda D-0012·ab12cd]») siempre pasa: el Processor comprueba que quien responde sea
        // un destinatario con rol «duda» y que el código de la duda sea el correcto.
        if (preg_match('/\[Duda D-\d+\x{00B7}[0-9a-f]{6}\]/iu', $subject) === 1) {
            return null;
        }
        $from = strtolower(trim($fromAddress));
        if ($this->matches($from, $this->discard)) {
            return 'aviso_automatico';
        }
        if ($this->allowed !== [] && !$this->matches($from, $this->allowed)) {
            return 'remitente';
        }

        return null;
    }

    /** @param list<string> $list */
    private function matches(string $from, array $list): bool
    {
        $domain = str_contains($from, '@') ? substr($from, (int) strrpos($from, '@') + 1) : $from;
        foreach ($list as $item) {
            $item = ltrim($item, '@');
            if ($item === '') {
                continue;
            }
            if ($from === $item || $domain === $item || str_ends_with($domain, '.' . $item)) {
                return true;
            }
        }

        return false;
    }
}
