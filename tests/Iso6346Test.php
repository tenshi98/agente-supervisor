<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Attachments\Iso6346;
use PHPUnit\Framework\TestCase;

final class Iso6346Test extends TestCase
{
    public function testCodigosValidosConocidos(): void
    {
        $this->assertTrue(Iso6346::isValid('CSQU3054383'));
        $this->assertTrue(Iso6346::isValid('csqu 305438-3'));   // tolera espacios, guion y minúsculas
        $this->assertTrue(Iso6346::isValid('MSKU9070323'));
    }

    public function testDigitoVerificadorIncorrecto(): void
    {
        $this->assertFalse(Iso6346::isValid('CSQU3054384'));
    }

    public function testFormatoInvalido(): void
    {
        $this->assertFalse(Iso6346::isValid('CSQX3054383'));     // la 4.ª letra debe ser U, J o Z
        $this->assertFalse(Iso6346::isValid('CSQU305438'));
        $this->assertFalse(Iso6346::isValid(''));
    }

    public function testFindValidEnTexto(): void
    {
        $text = "Retiro de CSQU 3054383 y MSKU-907032-3. Telefono 987654321. Falso: CSQU3054384";
        $this->assertSame(['CSQU3054383', 'MSKU9070323'], Iso6346::findValid($text));
    }
}
