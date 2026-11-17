<?php

declare(strict_types=1);

namespace Agente\Llm;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * API de mensajes de Anthropic por HTTP. Ahorro de tokens:
 *  - las instrucciones (reglas/*.md) van con cache_control: desde la segunda llamada cuestan ~10 %;
 *  - el que arma el contenido decide qué enviar (solo texto, o el original solo si hace falta).
 * Precios por millón de tokens configurables (AI_PRICE_IN_PER_MTOK / AI_PRICE_OUT_PER_MTOK).
 */
final class AnthropicClient implements LlmClient
{
    private const URL = 'https://api.anthropic.com/v1/messages';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly float $priceInPerMTok,
        private readonly float $priceOutPerMTok,
        private readonly int $timeout = 120,
        private readonly ?Client $http = null,
        private readonly int $maxAttempts = 3,
    ) {
    }

    public function complete(string $system, array $content, int $maxTokens): LlmResponse
    {
        $http = $this->http ?? new Client(['timeout' => $this->timeout, 'connect_timeout' => 15]);
        $body = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => [['role' => 'user', 'content' => $content]],
        ];

        $last = 'sin respuesta';
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $res = $http->post(self::URL, [
                    'headers' => [
                        'x-api-key' => $this->apiKey,
                        'anthropic-version' => '2023-06-01',
                        'content-type' => 'application/json',
                    ],
                    'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'http_errors' => false,
                ]);
            } catch (GuzzleException $e) {
                $last = 'red: ' . $e->getMessage();
                $this->pause($attempt);
                continue;
            }

            $code = $res->getStatusCode();
            $raw = (string) $res->getBody();
            if (in_array($code, [429, 500, 502, 503, 529], true)) {
                $last = "HTTP {$code}";
                $this->pause($attempt);
                continue;
            }
            if ($code !== 200) {
                throw new LlmException("Anthropic HTTP {$code}: " . mb_substr($raw, 0, 300), false);
            }

            return $this->parse($raw);
        }

        throw new LlmException("Anthropic no respondió tras {$this->maxAttempts} intentos ({$last})", true);
    }

    private function parse(string $raw): LlmResponse
    {
        $r = json_decode($raw, true);
        if (!is_array($r)) {
            throw new LlmException('Respuesta de Anthropic ilegible', true);
        }
        $stop = (string) ($r['stop_reason'] ?? '');
        if ($stop === 'refusal') {
            throw new LlmException('La IA rechazó el contenido (refusal)', false);
        }
        if ($stop === 'max_tokens') {
            throw new LlmException('La respuesta de la IA se cortó (max_tokens); suba max_tokens_salida en reglas/decision.json', false);
        }
        $text = '';
        foreach (($r['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }
        $u = is_array($r['usage'] ?? null) ? $r['usage'] : [];
        $in = (int) ($u['input_tokens'] ?? 0);
        $write = (int) ($u['cache_creation_input_tokens'] ?? 0);
        $read = (int) ($u['cache_read_input_tokens'] ?? 0);
        $out = (int) ($u['output_tokens'] ?? 0);
        // Escritura de caché cuesta 1,25x; lectura 0,1x.
        $cost = (($in + 1.25 * $write + 0.1 * $read) * $this->priceInPerMTok + $out * $this->priceOutPerMTok) / 1_000_000;

        return new LlmResponse($text, $in + $write + $read, $out, (string) ($r['model'] ?? $this->model), round($cost, 5));
    }

    private function pause(int $attempt): void
    {
        if ($attempt < $this->maxAttempts) {
            sleep(3 * $attempt);
        }
    }
}
