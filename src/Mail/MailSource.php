<?php

declare(strict_types=1);

namespace Agente\Mail;

/**
 * Origen de correo (Gmail API, IMAP...). Siempre SOLO LECTURA: no marca como leído, no mueve, no borra.
 */
interface MailSource
{
    /** Lanza excepción si no puede autenticarse. */
    public function testConnection(): void;

    /**
     * Mensajes posteriores al checkpoint de una carpeta (IMAP) o etiqueta (Gmail).
     *
     * @param callable(string $fromAddress, string $subject): ?string $prefilter devuelve el motivo de descarte
     *        o null si el mensaje pasa; los descartados se devuelven sin cuerpo ni adjuntos.
     */
    public function fetch(string $folder, Checkpoint $since, int $limit, callable $prefilter): MailBatch;
}
