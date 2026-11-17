<?php

declare(strict_types=1);

namespace Agente\Llm;

final class LlmResponse
{
    public function __construct(
        public readonly string $text,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly string $model,
        public readonly float $costUsd,
    ) {
    }
}
