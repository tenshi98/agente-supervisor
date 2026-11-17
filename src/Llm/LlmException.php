<?php

declare(strict_types=1);

namespace Agente\Llm;

use RuntimeException;

final class LlmException extends RuntimeException
{
    /** @param bool $retryable true si reintentar en otra corrida tiene sentido (red, saturación) */
    public function __construct(string $message, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}
