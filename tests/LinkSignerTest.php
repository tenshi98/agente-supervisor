<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Support\LinkSigner;
use PHPUnit\Framework\TestCase;

final class LinkSignerTest extends TestCase
{
    private const SECRET = 'a-very-long-test-secret-of-at-least-32-chars';

    public function testTokenValidoSeVerifica(): void
    {
        $s = new LinkSigner(self::SECRET);
        $claims = $s->verify($s->issue(12, 1, 7, 3600, 1000), 1500);
        $this->assertSame(['q' => 12, 't' => 1, 'r' => 7, 'e' => 4600], $claims);
    }

    public function testTokenVencidoSeRechaza(): void
    {
        $s = new LinkSigner(self::SECRET);
        $this->assertNull($s->verify($s->issue(1, 1, 1, 60, 1000), 2000));
    }

    public function testFirmaAlteradaSeRechaza(): void
    {
        $s = new LinkSigner(self::SECRET);
        [$payload, $sig] = explode('.', $s->issue(1, 1, 1, 3600, 1000));
        $forged = rtrim(strtr(base64_encode(json_encode(['q' => 99, 't' => 1, 'r' => 1, 'e' => 9999999999])), '+/', '-_'), '=');
        $this->assertNull($s->verify($forged . '.' . $sig, 1500));
        $this->assertNull($s->verify($payload . '.' . strrev($sig), 1500));
    }

    public function testOtroSecretoSeRechaza(): void
    {
        $a = new LinkSigner(self::SECRET);
        $b = new LinkSigner('another-very-long-test-secret-32-chars!!');
        $this->assertNull($b->verify($a->issue(1, 1, 1, 3600, 1000), 1500));
    }

    public function testBasuraSeRechaza(): void
    {
        $s = new LinkSigner(self::SECRET);
        foreach (['', 'abc', 'a.b.c', '.', 'x.'] as $t) {
            $this->assertNull($s->verify($t));
        }
    }

    public function testSecretoCortoLanzaExcepcion(): void
    {
        $this->expectException(\RuntimeException::class);
        new LinkSigner('corto');
    }
}
