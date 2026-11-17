<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Rules\RuleSet;
use PHPUnit\Framework\TestCase;

final class ReglasTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/reglas_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    private function write(string $f, string $c): void
    {
        file_put_contents($this->dir . '/' . $f, $c);
    }

    public function testCargaMdJsonYAccesoPorRuta(): void
    {
        $this->write('02_b.md', 'B');
        $this->write('01_a.md', 'A');
        $this->write('decision.json', '{"confianza":{"minima":"alta"},"campos_obligatorios":{"x":["identificador"]},"tipos_que_se_procesan":["x"]}');
        $r = RuleSet::load($this->dir);
        $this->assertSame("A\n\nB", $r->prompt);
        $this->assertSame('alta', $r->get('confianza.minima'));
        $this->assertSame('dflt', $r->get('no.existe', 'dflt'));
        $this->assertSame(['identificador'], $r->requiredFields('x'));
        $this->assertSame([], $r->requiredFields('otro'));
        $this->assertSame(['x'], $r->processedTypes());
        $this->assertSame([], $r->knownPlaces());
    }

    public function testLugaresConocidos(): void
    {
        $this->write('a.md', 'A');
        $this->write('decision.json', '{}');
        $this->write('lugares.json', '{"genericos":["san antonio"],"lugares":[{"nombre":"Terminal X","alias":["tx"]},{"alias":["sin nombre"]}]}');
        $r = RuleSet::load($this->dir);
        $this->assertCount(1, $r->knownPlaces());
        $this->assertSame(['san antonio'], $r->genericPlaces());
    }

    public function testJsonInvalidoFalla(): void
    {
        $this->write('a.md', 'A');
        $this->write('decision.json', '{malo');
        $this->expectException(\RuntimeException::class);
        RuleSet::load($this->dir);
    }

    public function testReglasDelProyectoSonValidas(): void
    {
        $r = RuleSet::load(dirname(__DIR__) . '/reglas');
        $this->assertStringContainsString('JSON', $r->prompt);
        $this->assertSame(['instruccion_transporte'], $r->processedTypes());
        $this->assertContains('identificador', $r->requiredFields('instruccion_transporte'));
    }

    public function testReglasV2DelProyecto(): void
    {
        $r = RuleSet::load(dirname(__DIR__) . '/reglas');
        $this->assertStringContainsString('inicio', $r->prompt);
        $this->assertContains('lugares.inicio', $r->requiredFields('instruccion_transporte'));
        $this->assertSame('puerto central', $r->addressRules()['dpw']);
        $this->assertSame('sai', $r->zoneOf('san antonio'));
        $this->assertNull($r->zoneOf('puerto'));
        $this->assertContains('agrosuper', $r->brands());
        $this->assertArrayHasKey('dpw', $r->seedAliases());
        $this->assertSame(60, $r->get('cruce.gracia_min'));
        $this->assertSame(72, $r->get('cruce.ventana_horas'));
        $this->assertSame(['guardar_caso', 'crear_duda'], $r->actionsOf('abierta'));
        $this->assertNull($r->actionFor('no_existe'));
    }
}
