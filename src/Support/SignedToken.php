<?php

declare(strict_types=1);

namespace Agente\Support;

use RuntimeException;

/**
 * Token firmado (HMAC-SHA256) con datos y vencimiento, para enlaces de administración.
 * Lleva un "propósito" dentro de la firma: un token emitido para un fin no sirve para otro
 * (p. ej. un enlace de duda no sirve para conectar una casilla).
 */
final class SignedToken
{
    public function __construct(private readonly string $secret, private readonly string $purpose)
    {
        if (strlen($secret) < 32) {
            throw new RuntimeException('AGENT_LINK_SECRET debe tener al menos 32 caracteres.');
        }
    }

    /** @param array<string,int|string> $claims */
    public function issue(array $claims, int $ttlSeconds, ?int $now = null): string
    {
        $claims['e'] = ($now ?? time()) + $ttlSeconds;
        $payload = self::b64(json_encode($claims, JSON_THROW_ON_ERROR));

        return $payload . '.' . self::b64($this->mac($payload));
    }

    /** @return array<string,mixed>|null null si la firma es inválida, es de otro propósito o venció */
    public function verify(string $token, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        $sig = self::unb64($parts[1]);
        if ($sig === null || !hash_equals($this->mac($parts[0]), $sig)) {
            return null;
        }
        $json = self::unb64($parts[0]);
        $data = $json === null ? null : json_decode($json, true);
        if (!is_array($data) || !isset($data['e']) || (int) $data['e'] < ($now ?? time())) {
            return null;
        }

        return $data;
    }

    private function mac(string $payload): string
    {
        return hash_hmac('sha256', $this->purpose . '|' . $payload, $this->secret, true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): ?string
    {
        $r = base64_decode(strtr($s, '-_', '+/'), true);

        return $r === false ? null : $r;
    }
}
