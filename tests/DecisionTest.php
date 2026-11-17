<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Attachments\Iso6346;
use Agente\Decision\DecisionEngine;
use Agente\Decision\DecisionResult;
use Agente\Decision\Doubt;
use Agente\Decision\ItemResult;
use Agente\Resolve\Resolution;
use Agente\Rules\RuleSet;
use Agente\Understanding\Extraction;
use PHPUnit\Framework\TestCase;

final class DecisionTest extends TestCase
{
    private DecisionEngine $engine;
    private string $cont;
    /** @var array<string,Resolution> */
    private array $base;

    protected function setUp(): void
    {
        $rules = new RuleSet('prompt', [
            'confianza' => ['minima' => 'media'],
            'tipos_que_se_procesan' => ['instruccion_transporte'],
            'campos_obligatorios' => ['instruccion_transporte' => ['identificador', 'lugares.inicio']],
            'validaciones' => ['contenedor_iso6346' => true, 'fechas_formato' => true],
            'lugares' => ['similitud_minima_candidato' => 55, 'max_candidatos' => 4],
        ], ['genericos' => ['San Antonio']]);
        $this->engine = new DecisionEngine($rules);
        $this->base = $this->auto('inicio', 'TPC', 'Terminal Puerto Central') + $this->auto('inicio', 'Puerto Central', 'Terminal Puerto Central');
        for ($d = 0; $d <= 9; $d++) {
            if (Iso6346::isValid('TEMU926016' . $d)) {
                $this->cont = 'TEMU926016' . $d;
            }
        }
    }

    /** Resolución automática de un texto en un punto. */
    private function auto(string $role, string $text, string $name, string $method = 'exacto', ?string $pid = 'P1'): array
    {
        return [Resolution::key($role, $text) => new Resolution(Resolution::AUTOMATIC, $method, $pid, $name)];
    }

    /** @param array<string,mixed> $carga @param array<string,mixed> $over */
    private function ex(array $carga = [], array $over = []): Extraction
    {
        $c = array_replace_recursive([
            'contenedor' => $this->cont, 'confianza' => 'alta',
            'lugares' => ['inicio' => [['texto' => 'TPC', 'contexto' => 'retirar en TPC', 'fuente' => 'cuerpo']]],
            'fechas' => ['retiro' => '2026-11-20 08:00'],
        ], $carga);

        return Extraction::fromArray(array_replace([
            'tipo_solicitud' => 'instruccion_transporte', 'relevante' => true, 'confianza' => 'alta', 'cargas' => [$c],
        ], $over));
    }

    public function testTodoResueltoAutomatico(): void
    {
        $res = $this->auto('inicio', 'TPC', 'Terminal Puerto Central');
        $r = $this->engine->decide($this->ex(), 1, $res, []);
        $this->assertSame(DecisionResult::DECIDED, $r->status);
        $this->assertSame(ItemResult::DECIDED, $r->items[0]->state());
        $p = $r->items[0]->data['lugares']['inicio'][0];
        $this->assertSame('Terminal Puerto Central', $p['resuelto']);
        $this->assertSame('P1', $p['place_id']);
        $this->assertSame('exacto', $p['metodo']);
        $this->assertSame(['automatica'], $r->decisions());
    }

    public function testLugarAbiertoGeneraDudaYRespuestaLaResuelve(): void
    {
        $ex = $this->ex(['lugares' => ['cliente' => [['texto' => 'Frutícola Los Andes', 'contexto' => 'entregar en', 'fuente' => 'cuerpo']]]]);
        $res = $this->auto('inicio', 'TPC', 'Terminal Puerto Central')
            + [Resolution::key('cliente', 'Frutícola Los Andes') => new Resolution(Resolution::OPEN, 'ninguno')];
        $r = $this->engine->decide($ex, 7, $res, []);
        $this->assertSame(DecisionResult::PENDING, $r->status);
        $d = $r->doubts();
        $this->assertCount(1, $d);
        $this->assertSame(Doubt::KIND_PLACE, $d[0]->kind);
        $this->assertSame(Doubt::LEVEL_OPEN, $d[0]->level);
        $this->assertSame('lugar|fruticola los andes', $d[0]->canon);
        $this->assertSame('cliente', $d[0]->field);
        $this->assertSame(['abierta'], $r->decisions());

        $ans = ['lugar|fruticola los andes' => ['tipo' => 'valor', 'valor' => 'Frutícola Melipilla', 'place_id' => 'P9']];
        $r2 = $this->engine->decide($ex, 7, $res, $ans);
        $this->assertSame(DecisionResult::DECIDED, $r2->status);
        $p = $r2->items[0]->data['lugares']['cliente'][0];
        $this->assertSame('usuario', $p['metodo']);
        $this->assertSame('P9', $p['place_id']);
    }

    public function testConfirmacionDeUnClic(): void
    {
        $ex = $this->ex(['lugares' => ['cliente' => [['texto' => 'Fruticola']]]]);
        $cands = [['place_id' => 'P5', 'nombre' => 'Frutícola Sur', 'direccion' => 'X', 'similitud' => 80]];
        $res = $this->auto('inicio', 'TPC', 'TPC') + [Resolution::key('cliente', 'Fruticola') => new Resolution(Resolution::CONFIRM, 'ia', null, null, $cands, 'P5', 'media')];
        $r = $this->engine->decide($ex, 1, $res, []);
        $d = $r->doubts()[0];
        $this->assertSame(Doubt::LEVEL_CONFIRM, $d->level);
        $this->assertSame('P5', $d->proposalPlaceId);
        $this->assertSame(['confirmacion'], $r->decisions());
    }

    public function testLugarGenericoYAutomaticoNoSePregunta(): void
    {
        $ex = $this->ex(['lugares' => ['final' => [['texto' => 'San Antonio']]]]);
        $res = $this->auto('inicio', 'TPC', 'TPC') + [Resolution::key('final', 'San Antonio') => new Resolution(Resolution::AUTOMATIC, 'generico', null, 'San Antonio', zone: 'sai')];
        $r = $this->engine->decide($ex, 1, $res, []);
        $this->assertSame(DecisionResult::DECIDED, $r->status);
        $this->assertSame('sai', $r->items[0]->data['lugares']['final'][0]['zona']);
    }

    public function testEquivalenciaIgnorarOmiteLaMencion(): void
    {
        $ex = $this->ex(['lugares' => ['cliente' => [['texto' => 'Naviera MSC']]]]);
        $res = $this->auto('inicio', 'TPC', 'TPC') + [Resolution::key('cliente', 'Naviera MSC') => new Resolution(Resolution::AUTOMATIC, 'equivalencia', ignored: true)];
        $r = $this->engine->decide($ex, 1, $res, []);
        $this->assertSame(DecisionResult::DECIDED, $r->status);
        $this->assertCount(0, $r->items[0]->data['lugares']['cliente']);
    }

    public function testConflictoGeneraDudaConOpciones(): void
    {
        $res = [Resolution::key('inicio', 'TPC') => new Resolution(Resolution::OPEN, 'conflicto', conflict: true, conflictOptions: ['Otro Terminal', 'Terminal Puerto Central'])];
        $r = $this->engine->decide($this->ex(), 1, $res, []);
        $d = $r->doubts()[0];
        $this->assertSame(Doubt::KIND_CONFLICT, $d->kind);
        $this->assertSame(['Otro Terminal', 'Terminal Puerto Central'], array_column($d->candidates, 'nombre'));
        $this->assertSame(['conflicto'], $r->decisions());
    }

    public function testResolucionAusenteSeTrataComoAbierta(): void
    {
        $r = $this->engine->decide($this->ex(), 1, [], []);
        $this->assertSame(Doubt::LEVEL_OPEN, $r->doubts()[0]->level);
    }

    public function testConfianzaBajaPreguntaYSeResuelveConRespuesta(): void
    {
        $ex = $this->ex(['confianza' => 'baja']);
        $r = $this->engine->decide($ex, 3, $this->base, []);
        $this->assertSame(Doubt::KIND_CARGO, $r->doubts()[0]->kind);
        $canon = $r->doubts()[0]->canon;
        $this->assertSame('m3|carga|carga|' . $this->cont, $canon);

        $this->assertSame(DecisionResult::DECIDED, $this->engine->decide($ex, 3, $this->base, [$canon => ['tipo' => 'confirmar']])->status);
        $corr = $this->engine->decide($ex, 3, $this->base, [$canon => ['tipo' => 'valor', 'valor' => 'es otro retiro']]);
        $this->assertSame('es otro retiro', $corr->items[0]->data['nota_usuario']);
        $this->assertSame(DecisionResult::IRRELEVANT, $this->engine->decide($ex, 3, $this->base, [$canon => ['tipo' => 'ignorar']])->status);
    }

    public function testFaltaInicioObligatorio(): void
    {
        $ex = Extraction::fromArray(['tipo_solicitud' => 'instruccion_transporte', 'relevante' => true, 'confianza' => 'alta',
            'cargas' => [['contenedor' => $this->cont, 'confianza' => 'alta', 'lugares' => ['inicio' => []]]]]);
        $r = $this->engine->decide($ex, 5, $this->base, []);
        $d = $r->doubts()[0];
        $this->assertSame(Doubt::KIND_MISSING, $d->kind);
        $this->assertSame('inicio', $d->field);
        $this->assertSame(['faltante'], $r->decisions());
        $r2 = $this->engine->decide($ex, 5, $this->base, [$d->canon => ['tipo' => 'valor', 'valor' => 'Puerto de Valparaíso']]);
        $this->assertSame(DecisionResult::DECIDED, $r2->status);
        $this->assertSame('usuario', $r2->items[0]->data['lugares']['inicio'][0]['metodo']);
        // "ignorar" significa que ese dato no aplica.
        $this->assertSame(DecisionResult::DECIDED, $this->engine->decide($ex, 5, $this->base, [$d->canon => ['tipo' => 'ignorar']])->status);
    }

    public function testContenedorConDigitoInvalido(): void
    {
        $bad = substr($this->cont, 0, 10) . (((int) $this->cont[10] + 1) % 10);
        $ex = $this->ex(['contenedor' => $bad]);
        $d = $this->engine->decide($ex, 9, $this->auto('inicio', 'TPC', 'TPC'), [])->doubts()[0];
        $this->assertSame(Doubt::KIND_DATA, $d->kind);
        $this->assertSame('contenedor', $d->field);
        $r = $this->engine->decide($ex, 9, $this->base, [$d->canon => ['tipo' => 'valor', 'valor' => strtolower($this->cont)]]);
        $this->assertSame(DecisionResult::DECIDED, $r->status);
        $this->assertSame($this->cont, $r->items[0]->data['contenedor']);
    }

    public function testFechaConFormatoInvalido(): void
    {
        $ex = $this->ex(['fechas' => ['retiro' => '20/11 temprano']]);
        $d = $this->engine->decide($ex, 2, $this->base, [])->doubts()[0];
        $this->assertSame('fecha_retiro', $d->field);
        $r = $this->engine->decide($ex, 2, $this->base, [$d->canon => ['tipo' => 'valor', 'valor' => '2026-11-20 07:30']]);
        $this->assertSame('2026-11-20 07:30', $r->items[0]->data['fechas']['retiro']);
    }

    public function testSinCargasPideIdentificador(): void
    {
        $ex = $this->ex([], ['cargas' => []]);
        $r = $this->engine->decide($ex, 4, $this->base, []);
        $d = $r->doubts()[0];
        $this->assertSame('m4|faltante|identificador|msg', $d->canon);
        $r2 = $this->engine->decide($ex, 4, $this->base, [$d->canon => ['tipo' => 'valor', 'valor' => 'BK 884422']]);
        $this->assertSame('BK 884422', $r2->items[0]->data['booking']);
        $this->assertSame(DecisionResult::IRRELEVANT, $this->engine->decide($ex, 4, $this->base, [$d->canon => ['tipo' => 'ignorar']])->status);
    }

    public function testCorreoNoRelevanteSeDescartaSinPreguntar(): void
    {
        $ex = $this->ex([], ['tipo_solicitud' => 'aviso', 'relevante' => false, 'cargas' => []]);
        $r = $this->engine->decide($ex, 1, $this->base, []);
        $this->assertSame(DecisionResult::IRRELEVANT, $r->status);
        $this->assertCount(0, $r->doubts());
    }

    public function testNoRelevanteConConfianzaBajaSeConfirma(): void
    {
        $ex = $this->ex([], ['tipo_solicitud' => 'aviso', 'relevante' => false, 'confianza' => 'baja']);
        $r = $this->engine->decide($ex, 6, $this->base, []);
        $this->assertSame(DecisionResult::PENDING, $r->status);
        $canon = $r->doubts()[0]->canon;
        $this->assertSame('m6|carga|tipo|msg', $canon);
        $this->assertSame(DecisionResult::IRRELEVANT, $this->engine->decide($ex, 6, $this->base, [$canon => ['tipo' => 'ignorar']])->status);
        // "sí es instrucción": se sigue con lo extraído
        $this->assertSame(DecisionResult::DECIDED, $this->engine->decide($ex, 6, $this->base, [$canon => ['tipo' => 'confirmar']])->status);
    }

    public function testVariasCargasMantienenClavesDistintas(): void
    {
        $ex = $this->ex([], ['cargas' => [
            ['contenedor' => $this->cont, 'confianza' => 'alta', 'lugares' => ['inicio' => [['texto' => 'TPC']]]],
            ['booking' => 'bk778899', 'confianza' => 'alta', 'lugares' => ['inicio' => [['texto' => 'Puerto Central']]]],
            ['confianza' => 'alta', 'lugares' => ['inicio' => [['texto' => 'TPC']]]],
        ]]);
        $r = $this->engine->decide($ex, 1, $this->base, []);
        $this->assertSame([$this->cont, 'bk:BK778899', 'n3'], array_map(static fn (ItemResult $i): string => $i->key, $r->items));
        // La tercera carga no trae identificador: queda pendiente.
        $this->assertSame(DecisionResult::PENDING, $r->status);
        $this->assertSame(ItemResult::PENDING, $r->items[2]->state());
        $this->assertSame(ItemResult::DECIDED, $r->items[0]->state());
    }
}
