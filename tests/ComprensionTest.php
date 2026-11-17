<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Llm\AnthropicClient;
use Agente\Llm\LlmClient;
use Agente\Llm\LlmException;
use Agente\Llm\LlmResponse;
use Agente\Rules\RuleSet;
use Agente\Support\Config;
use Agente\Support\Logger;
use Agente\Understanding\Extractor;
use Agente\Understanding\OriginalLoader;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** IA falsa: guarda lo que se le envió y responde con lo programado. */
final class FakeLlm implements LlmClient
{
    /** @var list<list<array<string,mixed>>> */
    public array $sent = [];

    /** @param list<string> $replies */
    public function __construct(private array $replies)
    {
    }

    public function complete(string $system, array $content, int $maxTokens): LlmResponse
    {
        $this->sent[] = $content;

        return new LlmResponse((string) array_shift($this->replies), 100, 50, 'fake', 0.001);
    }
}

final class ComprensionTest extends TestCase
{
    private string $tmp;
    private RuleSet $rules;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/comp_' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
        $this->rules = new RuleSet('INSTRUCCIONES', ['limites_ia' => ['max_caracteres_cuerpo' => 200, 'max_caracteres_por_adjunto' => 100, 'max_caracteres_total' => 2000, 'max_originales_por_correo' => 1]], []);
    }

    private function extractor(FakeLlm $llm): Extractor
    {
        $cfg = new Config([], $this->tmp);
        $log = new Logger($this->tmp . '/t.log');

        return new Extractor($llm, $this->rules, new OriginalLoader($cfg, $log), $log);
    }

    /** @return array<string,mixed> */
    private function msg(): array
    {
        return ['message_id' => 1, 'subject' => 'Retiro', 'sender' => 'a@b.cl', 'received' => '2026-11-17 10:00:00', 'body_text' => str_repeat('cuerpo largo ', 100)];
    }

    private function png(): string
    {
        $p = $this->tmp . '/scan.png';
        file_put_contents($p, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        return $p;
    }

    public function testSoloTextoSiTodoSeEntendio(): void
    {
        $llm = new FakeLlm(['{"tipo_solicitud":"instruccion_transporte","relevante":true,"confianza":"alta","legible":true,"necesita_original":[]}']);
        $att = [['filename' => 'guia.pdf', 'mime' => 'application/pdf', 'read_method' => 'texto_pdf', 'text_extracted' => 'ABCU1234567', 'valid' => 1, 'file_path' => null]];
        $res = $this->extractor($llm)->extract($this->msg(), $att);
        $this->assertCount(1, $llm->sent);                 // una sola llamada
        $this->assertCount(1, $llm->sent[0]);              // un solo bloque: texto
        $this->assertSame('text', $llm->sent[0][0]['type']);
        $this->assertStringContainsString('ADJUNTO: guia.pdf', $llm->sent[0][0]['text']);
        $this->assertStringContainsString('[…recortado]', $llm->sent[0][0]['text']); // el cuerpo se recorta
        $this->assertCount(1, $res->calls);
    }

    public function testPideElOriginalSoloDelAdjuntoIlegible(): void
    {
        $llm = new FakeLlm([
            '{"tipo_solicitud":"instruccion_transporte","relevante":true,"confianza":"media","legible":false,"necesita_original":["scan.png"]}',
            '{"tipo_solicitud":"instruccion_transporte","relevante":true,"confianza":"alta","legible":true,"cargas":[{"contenedor":"TEMU9260160"}]}',
        ]);
        $att = [
            ['filename' => 'scan.png', 'mime' => 'image/png', 'read_method' => 'ocr', 'text_extracted' => '@@#', 'valid' => 0, 'file_path' => $this->png()],
            ['filename' => 'otro.png', 'mime' => 'image/png', 'read_method' => 'ocr', 'text_extracted' => '', 'valid' => 0, 'file_path' => $this->png()],
        ];
        $res = $this->extractor($llm)->extract($this->msg(), $att);
        $this->assertCount(2, $llm->sent);
        $types = array_map(static fn (array $b): string => $b['type'], $llm->sent[1]);
        $this->assertSame(1, count(array_filter($types, static fn (string $t): bool => $t === 'image'))); // solo scan.png
        $this->assertSame('visual', 'visual');
        $this->assertSame(['extract', 'extract_visual'], array_map(static fn (array $c): string => $c['purpose'], $res->calls));
        $this->assertSame('alta', $res->extraction->confianza);
        $this->assertSame('TEMU9260160', $res->extraction->cargas[0]['contenedor']);
    }

    public function testIlegibleSinOriginalGuardadoNoHaceSegundaLlamada(): void
    {
        $llm = new FakeLlm(['{"relevante":true,"confianza":"baja","legible":false,"necesita_original":["x.pdf"]}']);
        $att = [['filename' => 'x.pdf', 'mime' => 'application/pdf', 'read_method' => 'ninguno', 'text_extracted' => '', 'valid' => 0, 'file_path' => null]];
        $res = $this->extractor($llm)->extract($this->msg(), $att);
        $this->assertCount(1, $llm->sent);
        $this->assertSame('baja', $res->extraction->confianza);
    }

    public function testClienteAnthropicParseaUsoYCalculaCosto(): void
    {
        $mock = new MockHandler([new Response(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => '{"ok":1}']], 'stop_reason' => 'end_turn', 'model' => 'm',
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 500, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
        ]))]);
        $hist = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($hist));
        $c = new AnthropicClient('k', 'claude-x', 2.0, 10.0, 5, new Client(['handler' => $stack]));
        $r = $c->complete('sys', [['type' => 'text', 'text' => 'hola']], 100);
        $this->assertSame('{"ok":1}', $r->text);
        $this->assertSame(1500, $r->tokensIn + $r->tokensOut);
        $this->assertEqualsWithDelta(0.007, $r->costUsd, 0.00001);
        $req = $hist[0]['request'];
        $this->assertSame('k', $req->getHeaderLine('x-api-key'));
        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('ephemeral', $body['system'][0]['cache_control']['type']); // instrucciones cacheadas
        $this->assertSame('claude-x', $body['model']);
    }

    public function testClienteAnthropicErroresNoReintentables(): void
    {
        $mk = static fn (Response $r): AnthropicClient => new AnthropicClient('k', 'm', 1, 1, 5, new Client(['handler' => HandlerStack::create(new MockHandler([$r]))]), 1);
        try {
            $mk(new Response(401, [], '{"error":"x"}'))->complete('s', [], 10);
            $this->assertTrue(false);
        } catch (LlmException $e) {
            $this->assertFalse($e->retryable);
        }
        try {
            $mk(new Response(200, [], json_encode(['stop_reason' => 'max_tokens', 'content' => []])))->complete('s', [], 10);
            $this->assertTrue(false);
        } catch (LlmException $e) {
            $this->assertStringContainsString('max_tokens', $e->getMessage());
        }
    }
}
