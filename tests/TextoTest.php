<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Text\Places;
use Agente\Text\TextCleaner;
use PHPUnit\Framework\TestCase;

final class TextoTest extends TestCase
{
    public function testLimpiaCitasYFirmasDeReenvio(): void
    {
        $t = "Retirar contenedor ABCU1234567\n\n\n> texto citado\n-----Original Message-----\nDe: otro\nmás historial";
        $c = TextCleaner::clean($t, 1000);
        $this->assertSame('Retirar contenedor ABCU1234567', $c);
    }

    public function testQuitaLineasLargasRepetidas(): void
    {
        $legal = 'Este mensaje es confidencial y está dirigido solo al destinatario indicado.';
        $c = TextCleaner::clean("Hola\n{$legal}\nOtra cosa\n{$legal}", 1000);
        $this->assertSame(1, substr_count($c, 'confidencial'));
    }

    public function testRecortaAlMaximo(): void
    {
        $c = TextCleaner::clean(str_repeat('a', 500), 100);
        $this->assertStringContainsString('[…recortado]', $c);
        $this->assertLessThanOrEqual(130, mb_strlen($c));
    }

    public function testNormalizaTildesYSignos(): void
    {
        $this->assertSame('deposito norte maipu', Places::normalize('  Depósito  NORTE, Maipú! '));
    }

    public function testParecidoExactoYCercano(): void
    {
        $this->assertSame(100, Places::similarity('Puerto Central', 'puerto  central'));
        $this->assertGreaterThan(70, Places::similarity('Terminal Pto Central', 'Terminal Puerto Central'));
        $this->assertLessThanOrEqual(40, Places::similarity('Frutícola Los Andes', 'Depósito Norte Maipú'));
    }

    public function testSiglaNoCalzaFuerteConNombreMasLargo(): void
    {
        $this->assertLessThanOrEqual(80, Places::similarity('DPW', 'DPW Los Morros Extraportuario Sur'));
    }
}
