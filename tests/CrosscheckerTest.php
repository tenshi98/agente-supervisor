<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Check\CaseEvaluator;
use Agente\Check\NeedRules;
use Agente\Check\PlaceComparer;
use PHPUnit\Framework\TestCase;

final class CrosscheckerTest extends TestCase
{
    private function data(array $lugares): array
    {
        return ['lugares' => $lugares];
    }

    private function place(string $texto, ?string $res, string $metodo = 'exacto', string $fuente = 'cuerpo', ?string $marca = null): array
    {
        return ['texto' => $texto, 'resuelto' => $res, 'metodo' => $metodo, 'fuente' => $fuente, 'marca' => $marca];
    }

    private function found(array $unit): array
    {
        return ['status' => 'ok', 'orden' => ['service_id' => '501'], 'unidad' => $unit];
    }

    public function testComparerSameAndDifferent(): void
    {
        $this->assertTrue(PlaceComparer::same('Puerto Central', 'PUERTO CENTRAL - Av. Argentina 1, San Antonio'));
        $this->assertTrue(PlaceComparer::same('SAAM San Antonio', 'saam san antonio'));
        $this->assertFalse(PlaceComparer::same('Puerto Central', 'TPS - Valparaíso'));
        $this->assertSame(PlaceComparer::UNKNOWN, PlaceComparer::compare([], ['San Antonio'], [], 'TPS - Valparaíso'));
        $this->assertSame(PlaceComparer::SAME, PlaceComparer::compare([], ['San Antonio'], [], 'SAAM SAN ANTONIO - x'));
        $this->assertSame(PlaceComparer::DIFFERENT, PlaceComparer::compare(['Puerto Central'], [], [], 'TPS'));
    }

    public function testNeedRules(): void
    {
        $this->assertTrue(NeedRules::needed('inicio', []));
        $this->assertFalse(NeedRules::needed('cliente', []));
        $this->assertTrue(NeedRules::needed('cliente', [['status_id' => 10]]));
        $this->assertFalse(NeedRules::needed('final', [['status_id' => 10]]));
        $this->assertTrue(NeedRules::needed('final', [['status_id' => 20]]));
    }

    public function testExecutedNeedsRealTruckAndPastDate(): void
    {
        $leg = ['status_id' => 20, 'from_name' => '', 'to_name' => '', 'truck_id' => 'T55', 'date_start' => '2026-11-01 10:00:00'];
        $this->assertTrue(NeedRules::executed('cliente', [$leg], strtotime('2026-11-02')));
        $this->assertFalse(NeedRules::executed('cliente', [$leg], strtotime('2026-10-30')));
        $this->assertFalse(NeedRules::executed('cliente', [['truck_id' => 'EXT0'] + $leg], strtotime('2026-11-02')));
        $this->assertFalse(NeedRules::executed('final', [$leg], strtotime('2026-11-02')));
    }

    public function testNoOrderRaisesMissingAlert(): void
    {
        $r = CaseEvaluator::evaluate($this->data([]), ['status' => 'no_encontrado', 'orden' => null, 'unidad' => null], null);
        $this->assertSame('falta_en_sistema', $r['state']);
        $this->assertSame('cruce_sin_orden', $r['alerts'][0]['decision']);
        $this->assertTrue($r['done']);
    }

    public function testErrorSkipsWithoutAlert(): void
    {
        $r = CaseEvaluator::evaluate($this->data([]), ['status' => 'error', 'orden' => null, 'unidad' => null], null);
        $this->assertTrue($r['skip']);
        $this->assertSame([], $r['alerts']);
    }

    public function testMatchIsOk(): void
    {
        $d = $this->data(['inicio' => [$this->place('DPW', 'Puerto Central', 'equivalencia')]]);
        $r = CaseEvaluator::evaluate($d, $this->found(['start_name' => 'PUERTO CENTRAL - Av 1']), ['status' => 'ok', 'tramos' => []]);
        $this->assertSame('ok', $r['state']);
        $this->assertSame([], $r['alerts']);
        $this->assertSame('501', $r['order_id']);
    }

    public function testEmptyPlaceOnlyAlertsWhenNeeded(): void
    {
        $d = $this->data([
            'inicio' => [$this->place('TPS', 'TPS')],
            'cliente' => [$this->place('Agrosuper Rancagua', 'AGROSUPER PLANTA RANCAGUA')],
            'final' => [$this->place('Depósito Norte', 'DEPOSITO NORTE')],
        ]);
        $r = CaseEvaluator::evaluate($d, $this->found(['start_name' => '', 'delivery_name' => '', 'end_name' => '']), ['status' => 'ok', 'tramos' => []]);
        $this->assertSame('lugar_vacio', $r['points']['inicio']);
        $this->assertSame('aun_no_requerido', $r['points']['cliente']);
        $this->assertSame('aun_no_requerido', $r['points']['final']);
        $this->assertCount(1, $r['alerts']);
        $this->assertFalse($r['done']); // puede cambiar dentro de la ventana

        $legs = ['status' => 'ok', 'tramos' => [['status_id' => 20, 'from_name' => '', 'to_name' => '', 'truck_id' => 'T1', 'date_start' => '2999-01-01 00:00:00']]];
        $r = CaseEvaluator::evaluate($d, $this->found(['start_name' => 'TPS', 'delivery_name' => '', 'end_name' => '']), $legs);
        $this->assertSame('lugar_vacio', $r['points']['cliente']);
        $this->assertSame('lugar_vacio', $r['points']['final']);
    }

    public function testDifferentVsExecutedAndContradiction(): void
    {
        $d = $this->data(['inicio' => [$this->place('DPW', 'Puerto Central', 'equivalencia')]]);
        $r = CaseEvaluator::evaluate($d, $this->found(['start_name' => 'TPS - Valparaíso']), ['status' => 'ok', 'tramos' => []]);
        $this->assertSame('distinto', $r['state']);
        $this->assertSame('cruce_distinto', $r['alerts'][0]['decision']);
        $this->assertSame(['dpw'], $r['contradicted']);

        $legs = ['status' => 'ok', 'tramos' => [['status_id' => 20, 'from_name' => 'TPS', 'to_name' => 'X', 'truck_id' => 'T1', 'date_start' => '2020-01-01 00:00:00']]];
        $r = CaseEvaluator::evaluate($d, $this->found(['start_name' => 'ZEAL - Sauce']), $legs);
        $this->assertSame('distinto_ejecutado', $r['state']);
        $this->assertSame('cruce_distinto_ejecutado', $r['alerts'][0]['decision']);
        $this->assertTrue($r['alerts'][0]['executed']);
        $this->assertSame([], $r['contradicted']); // un cambio de plan ejecutado no contradice la equivalencia
    }

    public function testPlaceInAnyLegIsOkEnRuta(): void
    {
        $d = $this->data(['inicio' => [$this->place('Puerto Central', 'Puerto Central')]]);
        $legs = ['status' => 'ok', 'tramos' => [['status_id' => 10, 'from_name' => 'PUERTO CENTRAL - x', 'to_name' => 'TLS', 'truck_id' => 'T1', 'date_start' => '2020-01-01']]];
        $r = CaseEvaluator::evaluate($d, $this->found(['start_name' => 'TLS - patio']), $legs);
        $this->assertSame('ok_en_ruta', $r['points']['inicio']);
        $this->assertSame([], $r['alerts']);
    }

    public function testGenericAndBrandNeverAlertWithoutTextMatch(): void
    {
        $d = $this->data([
            'inicio' => [$this->place('San Antonio', 'San Antonio', 'generico')],
            'cliente' => [$this->place('Agrosuper', 'agrosuper', 'marca', 'cuerpo', 'agrosuper')],
        ]);
        $r = CaseEvaluator::evaluate($d, $this->found(['start_name' => 'TPS - Valparaíso', 'delivery_name' => 'JUMBO']), ['status' => 'ok', 'tramos' => []]);
        $this->assertSame('no_verificable', $r['points']['inicio']);
        $this->assertSame('no_verificable', $r['points']['cliente']);
        $this->assertSame([], $r['alerts']);
    }

    public function testPendingDoubtWaits(): void
    {
        $d = $this->data(['inicio' => [['texto' => 'XYZ', 'resuelto' => null, 'metodo' => 'pendiente', 'fuente' => 'cuerpo']]]);
        $r = CaseEvaluator::evaluate($d, $this->found(['start_name' => 'TPS']), ['status' => 'ok', 'tramos' => []]);
        $this->assertSame('esperando_duda', $r['points']['inicio']);
        $this->assertFalse($r['done']);
    }

    public function testSummarizePicksMostSevere(): void
    {
        $this->assertSame('distinto', CaseEvaluator::summarize(['inicio' => 'ok', 'cliente' => 'distinto', 'final' => 'aun_no_requerido']));
        $this->assertSame('ok', CaseEvaluator::summarize(['inicio' => 'ok']));
    }
}
