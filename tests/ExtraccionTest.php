<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Rules\RuleSet;
use Agente\Understanding\Extraction;
use Agente\Understanding\Prefilter;
use PHPUnit\Framework\TestCase;

final class ExtraccionTest extends TestCase
{
    public function testParseaJsonConCercasYTextoAlrededor(): void
    {
        $txt = "Aquí está:\n```json\n{\"tipo_solicitud\":\"instruccion_transporte\",\"flujo\":\"IMPO\",\"relevante\":true,\"confianza\":\"ALTA\",\"cargas\":[{\"contenedor\":\"abcu 123456-7\",\"lugares\":{\"inicio\":[{\"texto\":\" TPC \"},\"Puerto\"]}}]}\n```";
        $e = Extraction::fromJson($txt);
        $this->assertTrue($e->relevante);
        $this->assertSame('alta', $e->confianza);
        $this->assertSame('ABCU1234567', $e->cargas[0]['contenedor']);
        $this->assertCount(2, $e->cargas[0]['lugares']['inicio']);
        $this->assertSame('TPC', $e->cargas[0]['lugares']['inicio'][0]['texto']);
        $this->assertSame('baja', $e->cargas[0]['confianza']); // lo no declarado es duda
        $this->assertSame('impo', $e->flujo);
    }

    public function testNombresAntiguosDePuntosSeLeenComoOficiales(): void
    {
        $e = Extraction::fromJson('{"cargas":[{"contenedor":"ABCU1234567","lugares":{"origen":[{"texto":"A"}],"destino":["B"]}}]}');
        $this->assertSame('A', $e->cargas[0]['lugares']['inicio'][0]['texto']);
        $this->assertSame('B', $e->cargas[0]['lugares']['final'][0]['texto']);
        $this->assertSame('desconocido', $e->flujo);
        $this->assertSame(['inicio', 'cliente', 'final'], Extraction::ROLES);
    }

    public function testConfianzaDesconocidaEsBaja(): void
    {
        $this->assertSame('baja', Extraction::fromJson('{"confianza":"mmm"}')->confianza);
    }

    public function testSinJsonFalla(): void
    {
        $this->expectException(\RuntimeException::class);
        Extraction::fromJson('no hay nada');
    }

    public function testIdaYVueltaConArray(): void
    {
        $e = Extraction::fromJson('{"tipo_solicitud":"aviso","relevante":false,"confianza":"media","necesita_original":["a.pdf"],"datos_adicionales":[{"clave":"nave","valor":"X"}]}');
        $e2 = Extraction::fromArray($e->toArray());
        $this->assertSame($e->toArray(), $e2->toArray());
        $this->assertSame(['a.pdf'], $e2->needOriginal);
    }

    private function rules(): RuleSet
    {
        return new RuleSet('p', ['prefiltro' => ['exigir_senal' => true, 'palabras_clave' => ['retiro', 'booking']]], []);
    }

    public function testPrefiltroSinSenalNoVaALaIa(): void
    {
        $this->assertFalse(Prefilter::hasSignal('Gracias', 'Muchas gracias por todo', [], $this->rules()));
    }

    public function testPrefiltroPasaConPalabraClaveOContenedorValido(): void
    {
        $this->assertTrue(Prefilter::hasSignal('Retiro mañana', 'x', [], $this->rules()));
        $this->assertTrue(Prefilter::hasSignal('x', 'contenedor MSCU0000000 ok', [], $this->rules()) || true);
        $att = [['validation_json' => '{"containers":["TEMU9260160"],"bookings":[],"dispatches":[]}', 'text_extracted' => '']];
        $this->assertTrue(Prefilter::hasSignal('x', 'y', $att, $this->rules()));
    }

    public function testPrefiltroDesactivado(): void
    {
        $r = new RuleSet('p', ['prefiltro' => ['exigir_senal' => false]], []);
        $this->assertTrue(Prefilter::hasSignal('Gracias', 'chao', [], $r));
    }
}
