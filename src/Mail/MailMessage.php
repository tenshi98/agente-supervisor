<?php

declare(strict_types=1);

namespace Agente\Mail;

use DateTimeImmutable;

final class MailMessage
{
    /** @param list<MailAttachment> $attachments */
    public function __construct(
        public readonly string $sourceId,
        public readonly string $folder,
        public readonly string $rfcMessageId,
        public readonly DateTimeImmutable $receivedAt,
        public readonly string $fromName,
        public readonly string $fromAddress,
        public readonly string $subject,
        public readonly string $bodyText,
        public readonly array $attachments,
        /** Posición para el checkpoint: internalDate en ms (Gmail) o UID (IMAP). */
        public readonly int $cursor,
        /** Si no pasó el filtro previo: motivo (y no trae cuerpo ni adjuntos). */
        public readonly ?string $discardReason = null,
    ) {
    }
}
