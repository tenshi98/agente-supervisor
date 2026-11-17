<?php

declare(strict_types=1);

namespace Agente\Support;

use RuntimeException;

/**
 * Cifrado de credenciales de casillas con sodium_crypto_secretbox.
 * La llave (32 bytes en base64) vive solo en config/.env (AGENT_VAULT_KEY).
 */
final class Vault
{
    private readonly string $key;

    public function __construct(string $keyBase64)
    {
        $key = base64_decode($keyBase64, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('AGENT_VAULT_KEY debe ser 32 bytes en base64 (php tools/make_keys.php).');
        }
        $this->key = $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Dato cifrado inválido.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plain === false) {
            throw new RuntimeException('No se pudo descifrar (llave incorrecta o dato alterado).');
        }

        return $plain;
    }
}
