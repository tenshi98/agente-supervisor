<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Mcp\FakeMcpClient;
use Agente\Mcp\OrdersClient;
use Agente\Notify\AlertNotifier;
use Agente\Notify\Templates;
use Agente\Persistence\Alerts;
use PHPUnit\Framework\TestCase;

final class AlertNotifierTest extends TestCase
{
    private function alert(string $kind, ?string $point, string $mail, string $sys = ''): array
    {
        return ['alert_id' => 7, 'kind' => $kind, 'point' => $point, 'flow' => 'impo', 'mail_value' => $mail, 'system_value' => $sys,
            'order_id' => '501', 'source' => 'cuerpo', 'container' => 'ABCU1234567', 'booking' => '', 'shipment_ref' => '', 'mail_subject' => 'Retiro <b>urgente</b>'];
    }

    private function revalidate(array $alert, callable $handler): ?array
    {
        $fake = new FakeMcpClient(['revalidar_avisos' => $handler]);
        $res = (new OrdersClient($fake))->revalidate(1, [['ref' => 'a7', 'contenedor' => 'ABCU1234567', 'despacho' => '', 'booking' => '', 'fecha_correo' => '2026-11-01', 'puntos' => []]]);

        return $res['a7'] ?? null;
    }

    public function testRevalidationDiscardsWhenSystemAlreadyHasData(): void
    {
        $a = $this->alert('lugar_vacio', 'inicio', 'TPS', '(vacío)');
        $loaded = $this->revalidate($a, fn () => ['status' => 'ok', 'orden' => ['service_id' => '501'], 'unidad' => ['start_name' => 'TPS - Valparaíso'], 'tramos' => []]);
        $this->assertSame('discard', AlertNotifier::verdict($a, $loaded));
        $empty = $this->revalidate($a, fn () => ['status' => 'ok', 'orden' => ['service_id' => '501'], 'unidad' => ['start_name' => ''], 'tramos' => []]);
        $this->assertSame('send', AlertNotifier::verdict($a, $empty));
    }

    public function testMissingOrderAlertDiscardedWhenOrderAppears(): void
    {
        $a = $this->alert('falta_en_sistema', null, 'instrucción recibida');
        $now = $this->revalidate($a, fn () => ['status' => 'ok', 'orden' => ['service_id' => '9'], 'unidad' => [], 'tramos' => []]);
        $this->assertSame('discard', AlertNotifier::verdict($a, $now));
        $still = $this->revalidate($a, fn () => ['status' => 'no_encontrado']);
        $this->assertSame('send', AlertNotifier::verdict($a, $still));
    }

    public function testDifferentDiscardedWhenNowMatches(): void
    {
        $a = $this->alert('distinto', 'inicio', 'Puerto Central', 'TPS');
        $same = $this->revalidate($a, fn () => ['status' => 'ok', 'orden' => ['service_id' => '1'], 'unidad' => ['start_name' => 'PUERTO CENTRAL - x'], 'tramos' => []]);
        $this->assertSame('discard', AlertNotifier::verdict($a, $same));
        $diff = $this->revalidate($a, fn () => ['status' => 'ok', 'orden' => ['service_id' => '1'], 'unidad' => ['start_name' => 'TPS'], 'tramos' => []]);
        $this->assertSame('send', AlertNotifier::verdict($a, $diff));
    }

    public function testErrorDefers(): void
    {
        $a = $this->alert('lugar_vacio', 'inicio', 'TPS');
        $this->assertSame('defer', AlertNotifier::verdict($a, $this->revalidate($a, fn () => ['status' => 'error'])));
        $this->assertSame('defer', AlertNotifier::verdict($a, null));
    }

    public function testOneMcpCallForAllAlerts(): void
    {
        $fake = new FakeMcpClient(['revalidar_avisos' => fn () => ['status' => 'no_encontrado']]);
        $items = [];
        for ($i = 1; $i <= 8; $i++) {
            $items[] = ['ref' => "a{$i}", 'contenedor' => 'ABCU123456' . $i, 'despacho' => '', 'booking' => '', 'fecha_correo' => '2026-11-01', 'puntos' => []];
        }
        $res = (new OrdersClient($fake))->revalidate(1, $items);
        $this->assertCount(8, $res);
        $this->assertCount(1, $fake->calls);
    }

    public function testCardUsesOperationsVocabularyAndTemplateRenders(): void
    {
        $card = AlertNotifier::card($this->alert('lugar_vacio', 'inicio', 'TPS', '(vacío)'), 'https://x/aviso.php?t=a', 'https://x/aviso.php?t=b');
        $this->assertSame('Retiro en puerto', $card['point_label']);
        $this->assertSame('Falta cargar', $card['badge']);
        $this->assertStringContainsString('retiro en puerto en TPS', $card['what_happened']);
        $this->assertStringContainsString('correo «Retiro <b>urgente</b>»', $card['source']);

        $t = new Templates(dirname(__DIR__) . '/plantillas', ['brand_name' => '', 'brand_footer' => '', 'dry_run' => true, 'base_url' => '']);
        $mail = $t->render('aviso_hora', ['date' => '01-11-2026', 'hour' => '10:00', 'total' => 1, 'counts' => ['falta_en_sistema' => 0, 'lugar_vacio' => 1, 'distinto' => 0], 'alerts' => [$card]]);
        $this->assertSame([], $t->missing());
        $this->assertStringNotContainsString('<b>urgente</b>', $mail['html']); // contenido externo escapado
        $this->assertStringContainsString('ABCU1234567', $mail['html']);
    }

    public function testSignatureIsStable(): void
    {
        $a = Alerts::signature(1, 5, 'distinto', 'inicio', 'A', 'B');
        $this->assertSame($a, Alerts::signature(1, 5, 'distinto', 'inicio', 'A', 'B'));
        $this->assertNotSame($a, Alerts::signature(1, 5, 'distinto', 'final', 'A', 'B'));
    }
}
