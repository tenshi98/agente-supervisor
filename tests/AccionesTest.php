<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Decision\DecisionResult;
use Agente\Decision\Doubt;
use Agente\Decision\ItemResult;
use Agente\Rules\RuleSet;
use PHPUnit\Framework\TestCase;

final class AccionesTest extends TestCase
{
    private const ALLOWED = ['descartar', 'guardar_caso', 'encolar_cruce', 'crear_duda', 'guardar_equivalencia', 'reprocesar_pendientes',
        'ignorar_caso', 'crear_aviso', 'solo_resumen_diario', 'descartar_aviso', 'ninguna', 'diferir_duda'];

    private RuleSet $rules;
    /** @var array<string,mixed> */
    private array $json;

    protected function setUp(): void
    {
        $dir = dirname(__DIR__) . '/reglas';
        $this->rules = RuleSet::load($dir);
        $this->json = json_decode((string) file_get_contents($dir . '/acciones.json'), true);
    }

    public function testCadaDecisionTieneAccionesPermitidas(): void
    {
        $this->assertNotEmpty($this->json['decisiones']);
        foreach (array_keys($this->json['decisiones']) as $dec) {
            $acts = $this->rules->actionsOf((string) $dec);
            $this->assertNotSame([], $acts, "La decisión {$dec} no tiene acciones");
            foreach ($acts as $a) {
                $this->assertContains($a, self::ALLOWED, "Acción no permitida en {$dec}: {$a}");
            }
        }
    }

    public function testLasAccionesDelComentarioCoincidenConLaListaPermitida(): void
    {
        foreach (self::ALLOWED as $a) {
            $this->assertStringContainsString($a, (string) $this->json['_comentario']);
        }
    }

    public function testDecisionesQueGeneranAvisoTienenTipoYCanal(): void
    {
        foreach ($this->json['decisiones'] as $name => $d) {
            if (in_array('crear_aviso', $d['acciones'], true) || in_array('solo_resumen_diario', $d['acciones'], true)) {
                $this->assertNotEmpty($d['tipo_aviso'] ?? '', $name);
                $this->assertContains($d['canal'], ['aviso', 'resumen'], $name);
            }
            if (in_array('crear_duda', $d['acciones'], true)) {
                $this->assertSame('duda', $d['canal'], $name);
                $this->assertFileExists(dirname(__DIR__) . '/plantillas/' . $d['plantilla'] . '.html', $name);
            }
        }
    }

    public function testNombresDeDecisionDelMotorExistenEnAcciones(): void
    {
        $kinds = [
            [Doubt::KIND_PLACE, Doubt::LEVEL_CONFIRM], [Doubt::KIND_PLACE, Doubt::LEVEL_OPEN], [Doubt::KIND_CONFLICT, Doubt::LEVEL_OPEN],
            [Doubt::KIND_MISSING, Doubt::LEVEL_OPEN], [Doubt::KIND_DATA, Doubt::LEVEL_OPEN], [Doubt::KIND_CARGO, Doubt::LEVEL_OPEN],
        ];
        $names = [];
        foreach ($kinds as [$k, $l]) {
            $item = new ItemResult('x', [], [new Doubt($k, 'inicio', 'c', 't', '', 's', [], 'x', $l)]);
            $names = array_merge($names, $item->decisions());
        }
        $names = array_merge($names, (new ItemResult('x', [], []))->decisions(), (new DecisionResult(DecisionResult::IRRELEVANT, []))->decisions());
        foreach (array_unique($names) as $n) {
            $this->assertNotNull($this->rules->actionFor($n), "Falta la decisión {$n} en reglas/acciones.json");
        }
        $this->assertSame([], (new ItemResult('x', [], [], true))->decisions());
        $this->assertSame(['descartar'], (new DecisionResult(DecisionResult::IRRELEVANT, []))->decisions());
    }
}
