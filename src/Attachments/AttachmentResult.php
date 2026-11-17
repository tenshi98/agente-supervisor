<?php

declare(strict_types=1);

namespace Agente\Attachments;

final class AttachmentResult
{
    /** @param array<string,mixed> $validation */
    public function __construct(
        public readonly string $method,      // texto_pdf | ocr | texto | ninguno
        public readonly string $text,
        public readonly array $validation,
        public readonly bool $valid,         // true = el texto basta; false = ilegible: la IA podrá pedir el original
        public readonly ?string $storedPath = null, // original guardado (solo si valid = false y es PDF/imagen)
    ) {
    }

    public function withStoredPath(string $path): self
    {
        return new self($this->method, $this->text, $this->validation, $this->valid, $path);
    }
}
