<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Mcp\CatalogClient;
use Agente\Mcp\FakeMcpClient;
use Agente\Mcp\HttpMcpClient;
use Agente\Mcp\McpException;
use Agente\Mcp\OrdersClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class McpClientTest extends TestCase
{
    /** @var list<array<string,mixed>> */
    private array $history = [];

    /** @param list<Response> $responses */
    private function client(array $responses, int $batchMax = 50): HttpMcpClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new HttpMcpClient('https://x.test/mcp', 'tok', $batchMax, 5, null, new Client(['handler' => $stack]), 3);
    }

    private static function ok(array $items): Response
    {
        return new Response(200, [], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => json_encode(['items' => $items])]]]]));
    }

    public function testOneCallForEightItemsAndSameRefs(): void
    {
        $items = [];
        $res = [];
        for ($i = 1; $i <= 8; $i++) {
            $items[] = ['ref' => "c$i", 'contenedor' => "X$i"];
            $res[] = ['ref' => "c$i", 'status' => $i === 5 ? 'no_encontrado' : 'ok'];
        }
        $out = $this->client([self::ok($res)])->batch(1, 'buscar_ordenes', $items);
        $this->assertCount(1, $this->history);
        $this->assertSame(array_column($items, 'ref'), array_keys($out));
        $this->assertSame('no_encontrado', $out['c5']['status']);
        $sent = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('tools/call', $sent['method']);
        $this->assertCount(8, $sent['params']['arguments']['items']);
        $this->assertSame('Bearer tok', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testMissingItemBecomesErrorWithoutBreakingBatch(): void
    {
        $out = $this->client([self::ok([['ref' => 'a', 'status' => 'ok']])])->batch(1, 't', [['ref' => 'a'], ['ref' => 'b']]);
        $this->assertSame('ok', $out['a']['status']);
        $this->assertSame('error', $out['b']['status']);
    }

    public function testChunkingAndDedup(): void
    {
        $c = $this->client([self::ok([['ref' => 'a', 'status' => 'ok'], ['ref' => 'b', 'status' => 'ok']]), self::ok([['ref' => 'c', 'status' => 'ok']])], 2);
        $out = $c->batch(1, 't', [['ref' => 'a'], ['ref' => 'b'], ['ref' => 'a'], ['ref' => 'c']]);
        $this->assertCount(2, $this->history);
        $this->assertSame(['a', 'b', 'c'], array_keys($out));
    }

    public function testTransportErrorAfterRetriesMarksAllAsError(): void
    {
        $c = $this->client([new Response(503), new Response(503), new Response(503)]);
        $out = $c->batch(1, 't', [['ref' => 'a'], ['ref' => 'b']]);
        $this->assertCount(3, $this->history);
        $this->assertSame('error', $out['a']['status']);
        $this->assertSame('error', $out['b']['status']);
    }

    public function testRetryThenSuccess(): void
    {
        $out = $this->client([new Response(429), self::ok([['ref' => 'a', 'status' => 'ok']])])->batch(1, 't', [['ref' => 'a']]);
        $this->assertSame('ok', $out['a']['status']);
    }

    public function testRefRequired(): void
    {
        $this->expectException(McpException::class);
        $this->client([])->batch(1, 't', [['x' => 1]]);
    }

    public function testFakeAndTypedClients(): void
    {
        $fake = new FakeMcpClient([
            'catalogo_lugares' => fn (array $i): array => ['candidatos' => [['place_id' => 'P1', 'nombre' => 'PUERTO CENTRAL', 'similitud' => 98]], 'marca' => ''],
            'tramos_de_ordenes' => fn (array $i): array => ['tramos' => [['status_id' => '20', 'from_name' => 'A', 'to_name' => 'B']]],
        ]);
        $r = (new CatalogClient($fake))->resolve(1, [['ref' => 'c1:inicio', 'texto' => 'DPW', 'rol' => 'inicio', 'flujo' => 'impo'], ['ref' => 'c2:inicio', 'texto' => 'DPW', 'rol' => 'inicio', 'flujo' => 'impo']]);
        $this->assertSame('P1', $r['c1:inicio']['candidatos'][0]['place_id']);
        $this->assertNull($r['c2:inicio']['marca']);
        $l = (new OrdersClient($fake))->legs(1, [['ref' => 'o1', 'service_id' => '9', 'contenedor' => 'X']]);
        $this->assertSame(20, $l['o1']['tramos'][0]['status_id']);
        $o = (new OrdersClient($fake))->find(1, [['ref' => 'c1', 'contenedor' => 'X', 'despacho' => '', 'booking' => '', 'fecha_correo' => '']]);
        $this->assertSame('no_encontrado', $o['c1']['status']);
        $this->assertSame([['catalogo_lugares', 2], ['tramos_de_ordenes', 1], ['buscar_ordenes', 1]], $fake->calls);
    }
}
