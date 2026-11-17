<?php

declare(strict_types=1);

namespace Agente\Understanding;

use Agente\Llm\LlmResponse;

final class ExtractResult
{
    /** @param list<array{purpose:string,response:LlmResponse}> $calls */
    public function __construct(public readonly Extraction $extraction, public readonly array $calls)
    {
    }
}
