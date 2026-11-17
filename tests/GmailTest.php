<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Mail\GmailOAuth;
use Agente\Support\LinkSigner;
use Agente\Support\SignedToken;
use PHPUnit\Framework\TestCase;

final class GmailTest extends TestCase
{
    private const SECRET = 'una-clave-secreta-de-al-menos-32-caracteres-ok';

    public function testTokenIdaYVuelta(): void
    {
        $s = new SignedToken(self::SECRET, 'gmail-connect');
        $t = $s->issue(['m' => 5, 't' => 1], 600, 1000);
        $c = $s->verify($t, 1100);
        $this->assertSame(5, $c['m']);
        $this->assertSame(1600, $c['e']);
    }

    public function testTokenVencidoOAlteradoNoValen(): void
    {
        $s = new SignedToken(self::SECRET, 'gmail-connect');
        $t = $s->issue(['m' => 5, 't' => 1], 60, 1000);
        $this->assertNull($s->verify($t, 5000));
        [$p, $sig] = explode('.', $t);
        $evil = rtrim(strtr(base64_encode('{"m":6,"t":1,"e":9999999999}'), '+/', '-_'), '=');
        $this->assertNull($s->verify($evil . '.' . $sig, 1100));
        $this->assertNull($s->verify('basura', 1100));
    }

    public function testTokenDeOtroPropositoNoSirve(): void
    {
        $gmail = new SignedToken(self::SECRET, 'gmail-connect');
        $otro = new SignedToken(self::SECRET, 'otro-fin');
        $this->assertNull($gmail->verify($otro->issue(['m' => 1, 't' => 1], 600)));
        // Un enlace de duda tampoco puede usarse para conectar una casilla.
        $duda = (new LinkSigner(self::SECRET))->issue(1, 1, 1, 600);
        $this->assertNull($gmail->verify($duda));
    }

    public function testClaveCortaFalla(): void
    {
        $this->expectException(\RuntimeException::class);
        new SignedToken('corta', 'x');
    }

    public function testUrlDeAutorizacionPideSoloLecturaYRefreshToken(): void
    {
        $url = (new GmailOAuth('cid.apps.googleusercontent.com', 'sec', 'https://h/agente/public/gmail.php'))->authUrl('ESTADO123', 'ops@gmail.com');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth', explode('?', $url)[0]);
        $this->assertSame('https://www.googleapis.com/auth/gmail.readonly', $q['scope']);
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame('consent', $q['prompt']);
        $this->assertSame('ESTADO123', $q['state']);
        $this->assertSame('https://h/agente/public/gmail.php', $q['redirect_uri']);
        $this->assertSame('ops@gmail.com', $q['login_hint']);
        $this->assertSame('code', $q['response_type']);
    }
}
