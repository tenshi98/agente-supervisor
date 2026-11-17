<?php

declare(strict_types=1);

namespace Agente\Mail;

final class Checkpoint
{
    public function __construct(
        public readonly ?int $uidValidity = null,
        public readonly ?int $lastUid = null,
        public readonly ?int $lastTsMs = null,
    ) {
    }
}
