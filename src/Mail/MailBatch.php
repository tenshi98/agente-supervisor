<?php

declare(strict_types=1);

namespace Agente\Mail;

final class MailBatch
{
    /** @param list<MailMessage> $messages ordenados del más antiguo al más nuevo */
    public function __construct(public readonly array $messages, public readonly Checkpoint $next)
    {
    }
}
