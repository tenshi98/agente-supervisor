<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Support\Vault;
use PHPUnit\Framework\TestCase;

final class VaultTest extends TestCase
{
    public function testIdaYVuelta(): void
    {
        $v = new Vault(Vault::generateKey());
        $enc = $v->encrypt('{"refresh_token":"abc"}');
        $this->assertStringNotContainsString('abc', $enc);
        $this->assertSame('{"refresh_token":"abc"}', $v->decrypt($enc));
    }

    public function testCadaCifradoEsDistinto(): void
    {
        $v = new Vault(Vault::generateKey());
        $this->assertNotSame($v->encrypt('x'), $v->encrypt('x'));
    }

    public function testLlaveIncorrectaFalla(): void
    {
        $enc = (new Vault(Vault::generateKey()))->encrypt('x');
        $this->expectException(\RuntimeException::class);
        (new Vault(Vault::generateKey()))->decrypt($enc);
    }

    public function testDatoAlteradoFalla(): void
    {
        $v = new Vault(Vault::generateKey());
        $raw = base64_decode($v->encrypt('hola'), true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";
        $this->expectException(\RuntimeException::class);
        $v->decrypt(base64_encode($raw));
    }

    public function testLlaveInvalida(): void
    {
        $this->expectException(\RuntimeException::class);
        new Vault('no-es-base64-de-32-bytes');
    }
}
