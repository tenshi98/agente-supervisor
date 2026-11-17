<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Pipeline\AnswerService;
use PHPUnit\Framework\TestCase;

final class AnswerServiceTest extends TestCase
{
    /** @return array<string,mixed> */
    private function q(array $o = []): array
    {
        return array_replace([
            'question_id' => 5, 'kind' => 'lugar', 'level' => 'abierta', 'field' => 'inicio', 'canon' => 'lugar|tps',
            'proposal_place_id' => '',
            'candidates_json' => json_encode([
                ['place_id' => 'P1', 'nombre' => 'TPS', 'direccion' => 'Valpo', 'similitud' => 80],
                ['place_id' => 'P2', 'nombre' => 'TLS', 'direccion' => 'SAI', 'similitud' => 60],
            ]),
        ], $o);
    }

    public function testElegirCandidatoYRecordar(): void
    {
        $this->assertSame(['tipo' => 'valor', 'valor' => 'TLS', 'place_id' => 'P2', 'recordar' => false], AnswerService::parse($this->q(), ['accion' => 'cand:1']));
        $this->assertSame(['tipo' => 'valor', 'valor' => 'TLS', 'place_id' => 'P2', 'recordar' => true], AnswerService::parse($this->q(), ['accion' => 'cand:1', 'recordar' => '1']));
        $this->assertSame(true, AnswerService::parse($this->q(), ['accion' => 'cand:0:recordar'])['recordar']);
    }

    public function testCandidatosAceptaTextosAntiguos(): void
    {
        $c = AnswerService::candidates(['candidates_json' => '["A","B"]']);
        $this->assertSame(['A', 'B'], array_column($c, 'nombre'));
    }

    public function testMailElegirOpcionYRecordar(): void
    {
        $this->assertSame('P1', AnswerService::parseMail($this->q(), "1\n\n> cita")['place_id']);
        $r = AnswerService::parseMail($this->q(), '2 R');
        $this->assertSame('P2', $r['place_id']);
        $this->assertTrue($r['recordar']);
    }

    public function testMailIgnorarYTextoLibre(): void
    {
        $this->assertSame(['tipo' => 'ignorar'], AnswerService::parseMail($this->q(), '0'));
        $this->assertSame(['tipo' => 'ignorar'], AnswerService::parseMail($this->q(), 'N'));
        $r = AnswerService::parseMail($this->q(), "Bodega Central Maipú\n\nSaludos");
        $this->assertSame('valor', $r['tipo']);
        $this->assertSame('Bodega Central Maipú', $r['valor']);
        $this->assertIsString(AnswerService::parseMail($this->q(), "   \n"));
    }

    public function testMailEnDudaDeUnClicUnoConfirmaLaPropuesta(): void
    {
        $q = $this->q(['level' => 'confirmacion', 'proposal_place_id' => 'P2']);
        $r = AnswerService::parseMail($q, '1');
        $this->assertSame('P2', $r['place_id']);
        $r = AnswerService::parseMail($q, '1 R');
        $this->assertTrue($r['recordar']);
        $this->assertSame('P2', AnswerService::parseMail($q, 'S')['place_id']);
    }

    public function testMailEnDudaDeCargaUnoConfirma(): void
    {
        $this->assertSame(['tipo' => 'confirmar'], AnswerService::parseMail($this->q(['kind' => 'carga']), '1'));
        $this->assertSame(['tipo' => 'ignorar'], AnswerService::parseMail($this->q(['kind' => 'carga']), '0'));
    }

    public function testSinPropuestaValidaNoConfirma(): void
    {
        $this->assertSame('Opción no válida.', AnswerService::parse($this->q(['level' => 'confirmacion', 'proposal_place_id' => 'X']), ['accion' => 'confirmar']));
    }
}
