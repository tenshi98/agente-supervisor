<?php

declare(strict_types=1);

namespace Agente\Llm;

/** Cliente de IA. Una sola operación: enviar instrucciones + contenido y recibir texto. */
interface LlmClient
{
    /**
     * @param string $system instrucciones fijas (se marcan para caché: son iguales en cada llamada)
     * @param list<array<string,mixed>> $content bloques de contenido (texto, imagen, documento)
     * @throws LlmException
     */
    public function complete(string $system, array $content, int $maxTokens): LlmResponse;
}
