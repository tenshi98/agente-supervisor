<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Mail\GmailApiSource;
use Agente\Mail\MailFilter;
use PHPUnit\Framework\TestCase;

final class MailFilterTest extends TestCase
{
    public function testSinListaPermitidaPasaTodo(): void
    {
        $this->assertNull((new MailFilter([], []))->reason('alguien@cliente.cl'));
    }

    public function testRemitenteNoPermitido(): void
    {
        $f = new MailFilter(['cliente.cl', 'ops@otro.com'], []);
        $this->assertNull($f->reason('juan@cliente.cl'));
        $this->assertNull($f->reason('x@sub.cliente.cl'));
        $this->assertNull($f->reason('OPS@OTRO.COM'));
        $this->assertSame('remitente', $f->reason('spam@malo.com'));
        $this->assertSame('remitente', $f->reason('x@nocliente.cl'));   // no basta con terminar igual
    }

    public function testAvisosAutomaticosSeDescartan(): void
    {
        $f = new MailFilter(['empresa.cl'], ['noreply@empresa.cl']);
        $this->assertSame('aviso_automatico', $f->reason('noreply@empresa.cl'));
        $this->assertNull($f->reason('jefe@empresa.cl'));
    }

    public function testParseFrom(): void
    {
        $this->assertSame(['Juan Pérez', 'juan@x.cl'], GmailApiSource::parseFrom('"Juan Pérez" <Juan@X.cl>'));
        $this->assertSame(['', 'solo@x.cl'], GmailApiSource::parseFrom('solo@x.cl'));
    }

    public function testHtmlToText(): void
    {
        $t = GmailApiSource::htmlToText('<style>p{}</style><p>Hola&nbsp;mundo</p><p>Retiro<br>TPSV</p>');
        $this->assertStringContainsString('Retiro', $t);
        $this->assertStringContainsString('TPSV', $t);
        $this->assertStringNotContainsString('p{}', $t);
    }
}
