<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Mail\Checkpoint;
use Agente\Mail\FolderSource;
use Agente\Notify\Email;
use Agente\Notify\FileMailer;
use Agente\Notify\QuestionMail;
use Agente\Notify\Templates;
use Agente\Notify\SmtpMailer;
use Agente\Pipeline\AnswerService;
use PHPUnit\Framework\TestCase;

final class SalidaTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sal_' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    /** @return array<string,mixed> */
    private function q(array $o = []): array
    {
        return array_replace([
            'question_id' => 12, 'token' => 'ab12cd', 'kind' => 'lugar', 'field' => 'cliente', 'canon' => 'lugar|x', 'text' => 'Fruti <b>cola</b>',
            'context' => 'entregar en "Fruti"', 'source' => 'cuerpo', 'message_id' => 3, 'level' => 'abierta', 'flow' => 'impo',
            'candidates_json' => '[{"place_id":"A1","nombre":"Frutícola A","direccion":"Calle 1","similitud":80},{"place_id":"B2","nombre":"Frutícola B","direccion":"","similitud":70}]',
        ], $o);
    }

    public function testCorreoDeDudaEscapaContenidoYTraeEnlace(): void
    {
        $m = QuestionMail::build(new Templates(dirname(__DIR__) . '/plantillas'), $this->q(), ['subject' => '<script>x</script>', 'sender' => 'a@b.cl', 'received' => '2026-11-17'], ['email' => 'jefe@x.cl', 'name' => 'Jefe'], 'http://h/agente/public/?t=TOK', 72, 'duda@agente.local');
        $this->assertStringContainsString('[Duda D-0012·ab12cd]', $m->subject);
        $this->assertStringContainsString('http://h/agente/public/?t=TOK', $m->text);
        $this->assertStringContainsString('http://h/agente/public/?t=TOK', $m->html);
        $this->assertStringNotContainsString('<script>', $m->html);
        $this->assertStringNotContainsString('<b>cola</b>', $m->html);
        $this->assertStringContainsString('Frutícola A', $m->html);
        $this->assertSame('jefe@x.cl', $m->to);
    }

    public function testMensajeSmtpEsMultipartYBloqueaInyeccionDeCabeceras(): void
    {
        $e = new Email("jefe@x.cl\r\nBcc: malo@x.cl", 'Jefe', "Duda ñandú\r\nBcc: otro@x.cl", "Línea\n.punto al inicio", '<p>hola</p>');
        $raw = SmtpMailer::build($e, 'agente@x.cl', 'Agente');
        $this->assertStringContainsString('multipart/alternative', $raw);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $raw);
        $headers = explode("\r\n\r\n", $raw)[0];
        $this->assertStringNotContainsString("\r\nBcc:", $headers);
        $this->assertStringContainsString('=?UTF-8?B?', $headers);
        $this->assertStringContainsString('To: Jefe <jefe@x.clBcc: malo@x.cl>', $headers);
    }

    public function testFileMailerNoEnviaYDejaVistaPrevia(): void
    {
        $fm = new FileMailer($this->tmp . '/outbox');
        $this->assertFalse($fm->isReal());
        $p = $fm->send(new Email('a@b.cl', '', 'Asunto', 'texto', '<p>html</p>'));
        $this->assertTrue(is_file((string) $p));
        $this->assertStringContainsString('<p>html</p>', (string) file_get_contents((string) $p));
    }

    public function testRespuestaValidaPorTipoDeDuda(): void
    {
        $q = $this->q();
        $this->assertSame(['tipo' => 'valor', 'valor' => 'Frutícola B', 'place_id' => 'B2', 'recordar' => false], AnswerService::parse($q, ['accion' => 'cand:1']));
        $this->assertSame(['tipo' => 'ignorar'], AnswerService::parse($q, ['accion' => 'ignorar']));
        $this->assertSame(['tipo' => 'valor', 'valor' => 'Otro nombre', 'recordar' => false], AnswerService::parse($q, ['accion' => 'valor', 'valor' => "  Otro\x00 nombre "]));
        $this->assertSame('Opción no válida.', AnswerService::parse($q, ['accion' => 'cand:9']));
        $this->assertSame('Opción no válida.', AnswerService::parse($q, ['accion' => 'confirmar'])); // solo en dudas de confianza
        $this->assertSame('Escribe un valor o elige otra opción.', AnswerService::parse($q, ['accion' => 'valor', 'valor' => '  ']));
        $this->assertSame('Elige una opción.', AnswerService::parse($q, []));
        $this->assertSame(['tipo' => 'confirmar'], AnswerService::parse($this->q(['kind' => 'carga']), ['accion' => 'confirmar']));
        $largo = AnswerService::parse($q, ['accion' => 'valor', 'valor' => str_repeat('x', 300)]);
        $this->assertTrue(is_string($largo));
    }

    public function testCarpetaComoOrigenDeCorreo(): void
    {
        file_put_contents($this->tmp . '/b.json', json_encode(['message_id' => '<2@x>', 'from' => 'spam@malo.com', 'subject' => 'Oferta', 'date' => '2026-11-17 10:05:00', 'body' => 'x']));
        file_put_contents($this->tmp . '/a.json', json_encode(['message_id' => '<1@x>', 'from' => 'ops@cliente.cl', 'subject' => 'Retiro', 'date' => '2026-11-17 10:00:00', 'body' => 'hola',
            'attachments' => [['filename' => 'g.txt', 'mime' => 'text/plain', 'path' => 'g.txt'], ['filename' => 'x', 'path' => '../etc/passwd']]]));
        file_put_contents($this->tmp . '/g.txt', 'contenido');
        $src = new FolderSource($this->tmp);
        $batch = $src->fetch('INBOX', new Checkpoint(), 10, static fn (string $f, string $s): ?string => str_contains($f, 'malo') ? 'remitente' : null);
        $this->assertCount(2, $batch->messages);
        $this->assertSame('<1@x>', $batch->messages[0]->rfcMessageId);          // más antiguo primero
        $this->assertCount(1, $batch->messages[0]->attachments);                // el '..' se ignora
        $this->assertSame('contenido', $batch->messages[0]->attachments[0]->content());
        $this->assertSame('remitente', $batch->messages[1]->discardReason);
        $this->assertSame('', $batch->messages[1]->bodyText);
        $this->assertGreaterThan(0, $batch->next->lastTsMs);
        // Con checkpoint no se relee lo anterior (salvo la misma marca, que deduplica el Ingestor)
        $again = $src->fetch('INBOX', $batch->next, 10, static fn (): ?string => null);
        $this->assertCount(1, $again->messages);
    }
}
