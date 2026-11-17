<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Attachments\TextSignals;
use PHPUnit\Framework\TestCase;

final class TextSignalsTest extends TestCase
{
    public function testTextoConContenedorValidoBasta(): void
    {
        $text = str_repeat('Instruccion de retiro en puerto. ', 4) . 'Contenedor CSQU3054383 retiro TPSV';
        $r = TextSignals::analyze($text);
        $this->assertTrue($r['valid']);
        $this->assertSame(['CSQU3054383'], $r['containers']);
    }

    public function testTextoCortoNoBasta(): void
    {
        $r = TextSignals::analyze('CSQU3054383');
        $this->assertFalse($r['valid']);
        $this->assertContains('texto_corto', $r['reasons']);
    }

    public function testSinIdentificadorNoBasta(): void
    {
        $r = TextSignals::analyze(str_repeat('texto largo sin datos utiles. ', 10));
        $this->assertFalse($r['valid']);
        $this->assertContains('sin_identificador', $r['reasons']);
    }

    public function testBookingYDespacho(): void
    {
        $text = str_repeat('relleno ', 20) . 'BOOKING: ABC12345 despacho I-2084123';
        $r = TextSignals::analyze($text);
        $this->assertSame(['ABC12345'], $r['bookings']);
        $this->assertSame(['I-2084123'], $r['dispatches']);
        $this->assertTrue($r['valid']);
    }
}
