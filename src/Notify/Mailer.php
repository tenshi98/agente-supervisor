<?php

declare(strict_types=1);

namespace Agente\Notify;

interface Mailer
{
    /**
     * @return string|null ruta del archivo de vista previa (FileMailer) o null si se envió por SMTP
     * @throws \RuntimeException si no se pudo enviar
     */
    public function send(Email $mail): ?string;

    /** true si el correo sale de verdad (SMTP); false si solo se deja una vista previa. */
    public function isReal(): bool;
}
