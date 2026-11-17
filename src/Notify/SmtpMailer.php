<?php

declare(strict_types=1);

namespace Agente\Notify;

use RuntimeException;

/**
 * Cliente SMTP mínimo (sin dependencias): SSL implícito (465), STARTTLS (587) o sin cifrado,
 * con AUTH LOGIN/PLAIN. Correo multipart/alternative (texto + HTML) en UTF-8.
 */
final class SmtpMailer implements Mailer
{
    /** @var resource|null */
    private $sock = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $security,   // ssl | starttls | none
        private readonly string $user,
        private readonly string $pass,
        private readonly string $from,
        private readonly string $fromName,
        private readonly int $timeout = 30,
    ) {
    }

    public function isReal(): bool
    {
        return true;
    }

    public function send(Email $mail): ?string
    {
        $raw = self::build($mail, $this->from, $this->fromName);
        try {
            $this->connect();
            $this->cmd('MAIL FROM:<' . self::addr($this->from) . '>', [250]);
            $this->cmd('RCPT TO:<' . self::addr($mail->to) . '>', [250, 251]);
            $this->cmd('DATA', [354]);
            fwrite($this->sock, self::dotStuff($raw) . "\r\n.\r\n");
            $this->expect([250]);
            $this->cmd('QUIT', [221], true);
        } finally {
            if (is_resource($this->sock)) {
                fclose($this->sock);
            }
            $this->sock = null;
        }

        return null;
    }

    /** Arma el mensaje completo (cabeceras + cuerpo). Público para poder probarlo sin red. */
    public static function build(Email $m, string $from, string $fromName): string
    {
        $boundary = '=_agente_' . bin2hex(random_bytes(8));
        $host = substr(strrchr($from, '@') ?: '@localhost', 1);
        $h = [
            'Date: ' . date('r'),
            'From: ' . self::mailbox($fromName, $from),
            'To: ' . self::mailbox($m->toName, $m->to),
            'Subject: ' . self::encodeHeader($m->subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::clean($host) . '>',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $part = static fn (string $type, string $body): string =>
            "--{$boundary}\r\nContent-Type: {$type}; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $body)) . "\r\n";

        return implode("\r\n", $h) . "\r\n\r\n" . $part('text/plain', $m->text) . $part('text/html', $m->html) . "--{$boundary}--";
    }

    private function connect(): void
    {
        $scheme = $this->security === 'ssl' ? 'ssl' : 'tcp';
        $s = @stream_socket_client("{$scheme}://{$this->host}:{$this->port}", $errno, $err, $this->timeout);
        if ($s === false) {
            throw new RuntimeException("SMTP: no se pudo conectar a {$this->host}:{$this->port} ({$err})");
        }
        stream_set_timeout($s, $this->timeout);
        $this->sock = $s;
        $this->expect([220]);
        $this->cmd('EHLO ' . (gethostname() ?: 'localhost'), [250]);
        if ($this->security === 'starttls') {
            $this->cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP: falló el cifrado STARTTLS');
            }
            $this->cmd('EHLO ' . (gethostname() ?: 'localhost'), [250]);
        }
        if ($this->user !== '') {
            $this->cmd('AUTH LOGIN', [334]);
            $this->cmd(base64_encode($this->user), [334]);
            $this->cmd(base64_encode($this->pass), [235]);
        }
    }

    /** @param list<int> $ok */
    private function cmd(string $line, array $ok, bool $lenient = false): void
    {
        fwrite($this->sock, $line . "\r\n");
        try {
            $this->expect($ok);
        } catch (RuntimeException $e) {
            if (!$lenient) {
                throw $e;
            }
        }
    }

    /** @param list<int> $ok */
    private function expect(array $ok): string
    {
        $resp = '';
        while (($l = fgets($this->sock, 1024)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($resp, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new RuntimeException('SMTP: respuesta inesperada: ' . trim($resp));
        }

        return $resp;
    }

    private static function dotStuff(string $raw): string
    {
        $unix = str_replace(["\r\n", "\r"], "\n", $raw);
        $stuffed = (string) preg_replace('/^\./m', '..', $unix);

        return str_replace("\n", "\r\n", $stuffed);
    }

    private static function clean(string $v): string
    {
        return trim(str_replace(["\r", "\n"], '', $v));
    }

    private static function addr(string $a): string
    {
        return self::clean(trim($a, '<> '));
    }

    private static function mailbox(string $name, string $addr): string
    {
        $addr = self::addr($addr);
        $name = self::clean($name);

        return $name === '' ? $addr : self::encodeHeader($name) . ' <' . $addr . '>';
    }

    private static function encodeHeader(string $v): string
    {
        $v = self::clean($v);

        return preg_match('/^[\x20-\x7E]*$/', $v) === 1 ? $v : '=?UTF-8?B?' . base64_encode($v) . '?=';
    }
}
