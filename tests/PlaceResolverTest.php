<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Mcp\CatalogClient;
use Agente\Mcp\FakeMcpClient;
use Agente\Resolve\PlaceChooser;
use Agente\Resolve\PlaceResolver;
use Agente\Resolve\Resolution;
use Agente\Rules\RuleSet;
use Agente\Text\Places;
use PHPUnit\Framework\TestCase;

final class PlaceResolverTest extends TestCase
{
    private RuleSet $rules;

    protected function setUp(): void
    {
        $this->rules = new RuleSet('p', [
            'lugares' => ['similitud_minima_candidato' => 55, 'max_candidatos' => 4, 'solo_activos' => true,
                'cliente_excluye_categorias' => ['puerto', 'extraportuario', 'deposito'],
                'umbral_exacto' => 99, 'umbral_parecido' => 90, 'ventaja_parecido' => 8, 'umbral_propuesta' => 75],
        ], [
            'genericos' => ['san antonio', 'puerto'],
            'zona_de_generico' => ['san antonio' => 'sai', 'puerto' => null],
            'marcas' => ['agrosuper'],
            'reglas_de_direccion' => ['_c' => 'x', 'dpw' => 'Puerto Central'],
        ]);
    }

    /** @param array<string,array<string,mixed>> $byText texto => resultado del catálogo */
    private function fake(array $byText): FakeMcpClient
    {
        return new FakeMcpClient(['catalogo_lugares' => static fn (array $it): array => $byText[$it['texto']] ?? ['status' => 'no_encontrado']]);
    }

    private function cand(string $id, string $name, int $sim, string $cat = 'cliente'): array
    {
        return ['place_id' => $id, 'nombre' => $name, 'direccion' => 'd', 'comuna' => 'c', 'categoria' => $cat, 'activo' => true, 'similitud' => $sim];
    }

    private function mention(string $text, string $role = 'inicio'): array
    {
        return ['texto' => $text, 'contexto' => 'ctx', 'rol' => $role, 'flujo' => 'impo'];
    }

    private function res(FakeMcpClient $f, array $mentions, array $aliases = [], ?PlaceChooser $ch = null): array
    {
        return (new PlaceResolver($this->rules, new CatalogClient($f), $ch))->resolve(1, $mentions, $aliases);
    }

    private function chooser(?string $pid, string $conf): PlaceChooser
    {
        return new class($pid, $conf) implements PlaceChooser {
            public int $calls = 0;

            public function __construct(private ?string $pid, private string $conf)
            {
            }

            public function choose(int $tenantId, array $items): array
            {
                $this->calls++;
                $out = [];
                foreach ($items as $it) {
                    $out[$it['ref']] = ['place_id' => $this->pid ?? $it['candidatos'][0]['place_id'], 'confianza' => $this->conf];
                }

                return $out;
            }
        };
    }

    public function testEquivalenciaVaPrimeroYNoLlamaAlCatalogo(): void
    {
        $f = $this->fake([]);
        $r = $this->res($f, [$this->mention('TPS')], ['tps' => ['value' => 'TPS Valparaíso', 'place_id' => 'P7', 'source' => 'usuario']]);
        $x = $r[Resolution::key('inicio', 'TPS')];
        $this->assertSame(Resolution::AUTOMATIC, $x->level);
        $this->assertSame('equivalencia', $x->method);
        $this->assertSame('P7', $x->placeId);
        $this->assertSame([], $f->calls);
    }

    public function testEquivalenciaIgnorar(): void
    {
        $r = $this->res($this->fake([]), [$this->mention('MSC')], ['msc' => ['value' => \Agente\Persistence\Aliases::IGNORE, 'place_id' => null, 'source' => 'usuario']]);
        $this->assertTrue($r[Resolution::key('inicio', 'MSC')]->ignored);
    }

    public function testGenericoNoSePreguntaYTrazaZona(): void
    {
        $f = $this->fake([]);
        $r = $this->res($f, [$this->mention('San Antonio'), $this->mention('Puerto')]);
        $a = $r[Resolution::key('inicio', 'San Antonio')];
        $this->assertSame('generico', $a->method);
        $this->assertSame('sai', $a->zone);
        $this->assertNull($r[Resolution::key('inicio', 'Puerto')]->zone);
        $this->assertSame([], $f->calls);
    }

    public function testDpwASecasEsPuertoCentralPorReglaDeDireccion(): void
    {
        $f = $this->fake([]);
        $x = $this->res($f, [$this->mention('DPW')])[Resolution::key('inicio', 'DPW')];
        $this->assertSame('Puerto Central', $x->name);
        $this->assertSame(Resolution::AUTOMATIC, $x->level);
        $this->assertSame([], $f->calls);
    }

    public function testDpWorldCisNoCaeEnLaRegla(): void
    {
        $f = $this->fake(['DP World CIS' => ['status' => 'ok', 'candidatos' => [$this->cand('C1', 'CIS', 100, 'puerto')]]]);
        $x = $this->res($f, [$this->mention('DP World CIS')])[Resolution::key('inicio', 'DP World CIS')];
        $this->assertSame('CIS', $x->name);
    }

    public function testConflictoAliasContraRegla(): void
    {
        $x = $this->res($this->fake([]), [$this->mention('DPW')], ['dpw' => ['value' => 'Otro', 'place_id' => null, 'source' => 'usuario']])[Resolution::key('inicio', 'DPW')];
        $this->assertTrue($x->conflict);
        $this->assertSame(Resolution::OPEN, $x->level);
        $this->assertSame(['Otro', 'Puerto Central'], $x->conflictOptions);
    }

    public function testClienteExcluyePuertosYDepositos(): void
    {
        $f = $this->fake(['Frutas' => ['status' => 'ok', 'candidatos' => [$this->cand('A', 'Puerto X', 100, 'puerto'), $this->cand('B', 'Depósito Y', 95, 'deposito')]]]);
        $x = $this->res($f, [$this->mention('Frutas', 'cliente')])[Resolution::key('cliente', 'Frutas')];
        $this->assertSame(Resolution::OPEN, $x->level);
        $this->assertSame([], $x->candidates);
        // el mismo candidato sí vale como inicio
        $y = $this->res($f, [$this->mention('Frutas', 'inicio')])[Resolution::key('inicio', 'Frutas')];
        $this->assertSame('A', $y->placeId);
    }

    public function testMarcaDelServidorOCliente(): void
    {
        $f = $this->fake(['Walmart' => ['status' => 'ok', 'marca' => 'walmart', 'candidatos' => []]]);
        $r = $this->res($f, [$this->mention('Walmart', 'cliente'), $this->mention('Agrosuper', 'cliente')]);
        $this->assertSame('marca', $r[Resolution::key('cliente', 'Walmart')]->method);
        $this->assertSame('agrosuper', $r[Resolution::key('cliente', 'Agrosuper')]->brand);
    }

    public function testExactoYParecidoAutomaticos(): void
    {
        $f = $this->fake([
            'A' => ['status' => 'ok', 'candidatos' => [$this->cand('1', 'A', 100), $this->cand('2', 'AB', 70)]],
            'B' => ['status' => 'ok', 'candidatos' => [$this->cand('3', 'B', 93), $this->cand('4', 'BB', 80)]],
        ]);
        $r = $this->res($f, [$this->mention('A'), $this->mention('B')]);
        $this->assertSame('exacto', $r[Resolution::key('inicio', 'A')]->method);
        $this->assertSame('parecido', $r[Resolution::key('inicio', 'B')]->method);
        $this->assertSame('3', $r[Resolution::key('inicio', 'B')]->placeId);
    }

    public function testLaIaElige(): void
    {
        $cands = ['status' => 'ok', 'candidatos' => [$this->cand('1', 'X1', 85), $this->cand('2', 'X2', 80)]];
        $f = fn () => $this->fake(['X' => $cands]);
        $alta = $this->res($f(), [$this->mention('X')], [], $this->chooser('2', 'alta'))[Resolution::key('inicio', 'X')];
        $this->assertSame(Resolution::AUTOMATIC, $alta->level);
        $this->assertSame('2', $alta->placeId);
        $this->assertSame('ia', $alta->method);
        $media = $this->res($f(), [$this->mention('X')], [], $this->chooser('1', 'media'))[Resolution::key('inicio', 'X')];
        $this->assertSame(Resolution::CONFIRM, $media->level);
        $this->assertSame('1', $media->proposalPlaceId);
        $baja = $this->res($f(), [$this->mention('X')], [], $this->chooser('1', 'baja'))[Resolution::key('inicio', 'X')];
        $this->assertSame(Resolution::OPEN, $baja->level);
        $this->assertCount(2, $baja->candidates);
    }

    public function testIaNuncaInventaUnIdFueraDeLosCandidatos(): void
    {
        $f = $this->fake(['X' => ['status' => 'ok', 'candidatos' => [$this->cand('1', 'X1', 85), $this->cand('2', 'X2', 80)]]]);
        $ch = new class() implements PlaceChooser {
            public function choose(int $tenantId, array $items): array
            {
                return [$items[0]['ref'] => ['place_id' => '999', 'confianza' => 'alta']];
            }
        };
        $x = $this->res($f, [$this->mention('X')], [], $ch)[Resolution::key('inicio', 'X')];
        $this->assertNotSame(Resolution::AUTOMATIC, $x->level);
        $this->assertNotSame('999', $x->placeId);
    }

    public function testLlmPlaceChooserDescartaIdsInventados(): void
    {
        $llm = new class() implements \Agente\Llm\LlmClient {
            public int $n = 0;

            public function complete(string $system, array $content, int $maxTokens): \Agente\Llm\LlmResponse
            {
                $this->n++;

                return new \Agente\Llm\LlmResponse('[{"ref":"a","place_id":"ZZZ","confianza":"alta"},{"ref":"b","place_id":"2","confianza":"media"}]', 1, 1, 'm', 0.0);
            }
        };
        $items = [];
        foreach (['a', 'b'] as $ref) {
            $items[] = ['ref' => $ref, 'texto' => 't', 'contexto' => '', 'rol' => 'inicio', 'flujo' => 'impo', 'candidatos' => [['place_id' => '1', 'nombre' => 'n', 'direccion' => '', 'similitud' => 80], ['place_id' => '2', 'nombre' => 'm', 'direccion' => '', 'similitud' => 70]]];
        }
        $out = (new \Agente\Resolve\LlmPlaceChooser($llm))->choose(1, $items);
        $this->assertNull($out['a']['place_id']);
        $this->assertSame('2', $out['b']['place_id']);
        $this->assertSame(1, $llm->n, 'una sola llamada a la IA para todo el lote');
    }

    public function testSinChooserUnaPropuestaAltaPideConfirmacion(): void
    {
        $f = $this->fake(['Y' => ['status' => 'ok', 'candidatos' => [$this->cand('1', 'Y1', 80), $this->cand('2', 'Y2', 75)]], 'Z' => ['status' => 'ok', 'candidatos' => [$this->cand('3', 'Z1', 60)]]]);
        $r = $this->res($f, [$this->mention('Y'), $this->mention('Z')]);
        $this->assertSame(Resolution::CONFIRM, $r[Resolution::key('inicio', 'Y')]->level);
        $this->assertSame(Resolution::OPEN, $r[Resolution::key('inicio', 'Z')]->level);
    }

    public function testNoEncontradoEsDudaAbiertaPeroErrorDelSistemaSeDifiere(): void
    {
        $f = new FakeMcpClient(['catalogo_lugares' => static fn (array $it): array => $it['texto'] === 'Caido' ? ['status' => 'error'] : ['status' => 'no_encontrado']]);
        $r = (new PlaceResolver($this->rules, new CatalogClient($f)))->resolve(1, [$this->mention('Nada'), $this->mention('Caido')], []);
        $this->assertSame(Resolution::OPEN, $r[Resolution::key('inicio', 'Nada')]->level);
        $this->assertSame(Resolution::DEFER, $r[Resolution::key('inicio', 'Caido')]->level);
    }

    public function testNTextosGeneranUnaSolaLlamadaAlCatalogoYDeduplica(): void
    {
        $by = [];
        $m = [];
        for ($i = 1; $i <= 8; $i++) {
            $by["L{$i}"] = ['status' => 'ok', 'candidatos' => [$this->cand((string) $i, "L{$i}", 100)]];
            $m[] = $this->mention("L{$i}");
        }
        $m[] = $this->mention('L1');
        $m[] = $this->mention('  l1 ');
        $f = $this->fake($by);
        $r = $this->res($f, $m);
        $this->assertCount(8, $r);
        $this->assertSame([['catalogo_lugares', 8]], $f->calls);
        $this->assertSame(Places::normalize('L1'), 'l1');
    }
}
