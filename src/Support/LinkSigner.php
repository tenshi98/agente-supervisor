<?php

declare(strict_types=1);

namespace Agente\Support;

/**
 * Enlaces firmados (HMAC-SHA256) para responder dudas desde public/index.php.
 *
 * El token lleva: duda, tenant, destinatario y vencimiento. La firma garantiza integridad;
 * el "un solo uso" se aplica en la BD (ndb_agent_questions.link_used) al registrar la respuesta.
 */
final class LinkSigner
{
    public function __construct(private readonly string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \RuntimeException('AGENT_LINK_SECRET debe tener al menos 32 caracteres.');
        }
    }

    public function issue(int $questionId, int $tenantId, int $recipientId, int $ttlSeconds, ?int $now = null): string
    {
        $payload = $this->b64(json_encode([
            'q' => $questionId,
            't' => $tenantId,
            'r' => $recipientId,
            'e' => ($now ?? time()) + $ttlSeconds,
        ], JSON_THROW_ON_ERROR));

        return $payload . '.' . $this->b64($this->mac($payload));
    }

    /** @return array{q:int,t:int,r:int,e:int}|null null si la firma es inválida o venció */
    public function verify(string $token, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        $sig = $this->unb64($parts[1]);
        if ($sig === null || !hash_equals($this->mac($parts[0]), $sig)) {
            return null;
        }
        $json = $this->unb64($parts[0]);
        $data = $json === null ? null : json_decode($json, true);
        if (!is_array($data) || !isset($data['q'], $data['t'], $data['r'], $data['e'])) {
            return null;
        }
        if ((int) $data['e'] < ($now ?? time())) {
            return null;
        }

        return ['q' => (int) $data['q'], 't' => (int) $data['t'], 'r' => (int) $data['r'], 'e' => (int) $data['e']];
    }

    private function mac(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret, true);
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function unb64(string $s): ?string
    {
        $r = base64_decode(strtr($s, '-_', '+/'), true);

        return $r === false ? null : $r;
    }
}
