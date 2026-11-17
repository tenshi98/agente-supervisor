<?php

declare(strict_types=1);

namespace Agente\Notify;

/** No envía nada: deja el correo como archivo en storage/outbox/ (dry_run, o SMTP sin configurar). */
final class FileMailer implements Mailer
{
    public function __construct(private readonly string $dir)
    {
    }

    public function send(Email $mail): ?string
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('No se pudo crear ' . $this->dir);
        }
        $base = $this->dir . '/' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $meta = "Para: {$mail->to}\nAsunto: {$mail->subject}\n\n";
        file_put_contents($base . '.txt', $meta . $mail->text);
        file_put_contents($base . '.html', $mail->html);

        return $base . '.html';
    }

    public function isReal(): bool
    {
        return false;
    }
}
