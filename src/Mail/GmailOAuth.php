<?php

declare(strict_types=1);

namespace Agente\Mail;

use Google\Client;
use Google\Service\Gmail;
use GuzzleHttp\Client as Http;
use RuntimeException;

/**
 * Autorización OAuth de una casilla Gmail (alcance de solo lectura). Entrega el refresh token que usa GmailApiSource.
 * Se pide siempre el consentimiento (prompt=consent) para que Google devuelva el refresh token.
 */
final class GmailOAuth
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly int $timeout = 30,
    ) {
    }

    public function authUrl(string $state, ?string $loginHint = null): string
    {
        $c = $this->client();
        $c->setState($state);
        if ($loginHint !== null && $loginHint !== '') {
            $c->setLoginHint($loginHint);
        }

        return $c->createAuthUrl();
    }

    /**
     * Canjea el código de autorización.
     *
     * @return array{refresh_token:string,email:string}
     * @throws RuntimeException si Google rechaza el código o no entrega refresh token
     */
    public function exchange(string $code): array
    {
        $c = $this->client();
        $token = $c->fetchAccessTokenWithAuthCode($code);
        if (isset($token['error'])) {
            throw new RuntimeException('Google rechazó la autorización: ' . ($token['error_description'] ?? $token['error']));
        }
        $refresh = (string) ($token['refresh_token'] ?? '');
        if ($refresh === '') {
            throw new RuntimeException('Google no entregó refresh token. Revoca el acceso de esta aplicación en https://myaccount.google.com/permissions y repite la autorización.');
        }
        $c->setAccessToken($token);
        $email = strtolower((string) (new Gmail($c))->users->getProfile('me')->getEmailAddress());
        if ($email === '') {
            throw new RuntimeException('No se pudo leer la dirección de la cuenta autorizada.');
        }

        return ['refresh_token' => $refresh, 'email' => $email];
    }

    private function client(): Client
    {
        $c = new Client();
        $c->setClientId($this->clientId);
        $c->setClientSecret($this->clientSecret);
        $c->setRedirectUri($this->redirectUri);
        $c->setScopes([Gmail::GMAIL_READONLY]);
        $c->setAccessType('offline');
        $c->setPrompt('consent');
        $c->setHttpClient(new Http(['timeout' => $this->timeout, 'connect_timeout' => 10]));

        return $c;
    }
}
