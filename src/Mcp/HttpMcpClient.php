<?php

declare(strict_types=1);

namespace Agente\Mcp;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/** JSON-RPC 2.0 (`tools/call`) sobre HTTPS con bearer. Parte en trozos de $batchMax y reintenta 2 veces en 429/5xx/timeout. */
final class HttpMcpClient implements McpClient
{
    private int $rpcId = 0;

    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly int $batchMax = 50,
        private readonly int $timeout = 30,
        private readonly ?McpLog $log = null,
        private readonly ?ClientInterface $http = null,
        private readonly int $maxAttempts = 3,
    ) {
    }

    public function batch(int $tenantId, string $tool, array $items, string $process = ''): array
    {
        $unique = [];
        foreach ($items as $it) {
            $ref = (string) ($it['ref'] ?? '');
            if ($ref === '') {
                throw new McpException('Todo elemento enviado por MCP necesita "ref"');
            }
            $it['ref'] = $ref;
            $unique[$ref] ??= $it;
        }
        $out = [];
        foreach (array_chunk(array_values($unique), max(1, $this->batchMax)) as $chunk) {
            $out += $this->call($tenantId, $tool, $chunk, $process);
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $chunk @return array<string,array<string,mixed>> */
    private function call(int $tenantId, string $tool, array $chunk, string $process): array
    {
        $t0 = microtime(true);
        $error = null;
        $byRef = [];
        try {
            $byRef = $this->send($tool, $chunk);
        } catch (McpException $e) {
            $error = $e->getMessage();
        }
        $out = [];
        $ok = 0;
        foreach ($chunk as $it) {
            $ref = $it['ref'];
            $r = $byRef[$ref] ?? null;
            if ($r === null) {
                $r = ['status' => 'error', 'error' => $error ?? 'el servidor no devolvió este elemento'];
            }
            $r['ref'] = $ref;
            $r['status'] = in_array($r['status'] ?? '', ['ok', 'no_encontrado', 'error'], true) ? $r['status'] : 'error';
            if ($r['status'] === 'ok') {
                $ok++;
            }
            $out[$ref] = $r;
        }
        $this->log?->record($tenantId, $tool, count($chunk), $ok, (int) round((microtime(true) - $t0) * 1000), $error, $process);

        return $out;
    }

    /** @param list<array<string,mixed>> $chunk @return array<string,array<string,mixed>> */
    private function send(string $tool, array $chunk): array
    {
        $http = $this->http ?? new Client(['timeout' => $this->timeout, 'connect_timeout' => 10]);
        $body = json_encode([
            'jsonrpc' => '2.0', 'id' => ++$this->rpcId, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => ['items' => $chunk]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $last = 'sin respuesta';
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $res = $http->request('POST', $this->url, [
                    'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
                    'body' => $body, 'http_errors' => false, 'timeout' => $this->timeout,
                ]);
            } catch (GuzzleException $e) {
                $last = 'red: ' . $e->getMessage();
                $this->pause($attempt);
                continue;
            }
            $code = $res->getStatusCode();
            if ($code === 429 || $code >= 500) {
                $last = "HTTP {$code}";
                $this->pause($attempt);
                continue;
            }
            if ($code !== 200) {
                throw new McpException("MCP HTTP {$code}");
            }

            return $this->parse((string) $res->getBody());
        }

        throw new McpException("MCP no respondió tras {$this->maxAttempts} intentos ({$last})");
    }

    /** @return array<string,array<string,mixed>> */
    private function parse(string $raw): array
    {
        $r = json_decode($raw, true);
        if (!is_array($r)) {
            throw new McpException('Respuesta MCP ilegible');
        }
        if (isset($r['error'])) {
            throw new McpException('MCP error ' . (string) ($r['error']['code'] ?? '') . ': ' . mb_substr((string) ($r['error']['message'] ?? ''), 0, 200));
        }
        $res = $r['result'] ?? null;
        if (!is_array($res)) {
            throw new McpException('Respuesta MCP sin result');
        }
        $payload = $res['structuredContent'] ?? null;
        if (!is_array($payload)) {
            $text = $res['content'][0]['text'] ?? null;
            $payload = is_string($text) ? json_decode($text, true) : null;
        }
        if (!is_array($payload) || !is_array($payload['items'] ?? null)) {
            throw new McpException('Respuesta MCP sin "items"');
        }
        if (($res['isError'] ?? false) === true) {
            throw new McpException('MCP isError: ' . mb_substr((string) ($payload['message'] ?? ''), 0, 200));
        }
        $out = [];
        foreach ($payload['items'] as $it) {
            if (is_array($it) && isset($it['ref'])) {
                $out[(string) $it['ref']] = $it;
            }
        }

        return $out;
    }

    private function pause(int $attempt): void
    {
        if ($attempt < $this->maxAttempts) {
            usleep(200000 * $attempt);
        }
    }
}
