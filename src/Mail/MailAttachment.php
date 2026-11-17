<?php

declare(strict_types=1);

namespace Agente\Mail;

use Closure;

/** Adjunto con descarga diferida: solo se baja el contenido cuando se necesita. */
final class MailAttachment
{
    private ?string $bytes = null;

    /** @param Closure():string $loader */
    public function __construct(
        public readonly string $filename,
        public readonly string $mime,
        public readonly int $size,
        public readonly string $partRef,
        private readonly Closure $loader,
    ) {
    }

    public function content(): string
    {
        return $this->bytes ??= ($this->loader)();
    }

    public function free(): void
    {
        $this->bytes = null;
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf' || str_ends_with(strtolower($this->filename), '.pdf');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/')
            || (bool) preg_match('/\.(png|jpe?g|tiff?|bmp|webp)$/i', $this->filename);
    }
}
