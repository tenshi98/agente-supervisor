<?php

declare(strict_types=1);

namespace Agente\Tests;

use Agente\Notify\Templates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplatesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tpl_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/_layout.html', '<html>{{{content}}}</html>');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    /** @param array<string,mixed> $vars @return array{subject:string,html:string,text:string} */
    private function mini(string $html, array $vars = [], string $txt = '', string $subject = 's'): array
    {
        file_put_contents($this->dir . '/t.html', $html);
        file_put_contents($this->dir . '/t.txt', $txt);
        file_put_contents($this->dir . '/t.subject.txt', $subject);

        return (new Templates($this->dir))->render('t', $vars);
    }

    public function testEscapaHtmlYPermiteCrudo(): void
    {
        $r = $this->mini('{{a}}|{{{a}}}', ['a' => '<b>"x"</b>'], '{{a}}', '{{a}}');
        $this->assertSame('<html>&lt;b&gt;&quot;x&quot;&lt;/b&gt;|<b>"x"</b></html>', $r['html']);
        $this->assertSame("<b>\"x\"</b>\n", $r['text']);
        $this->assertSame('<b>"x"</b>', $r['subject']);
    }

    public function testRutasConPuntosIfElseYEach(): void
    {
        $tpl = '{{u.name}}{{#if u.admin}}A{{else}}B{{/if}}{{#each rows}}[{{@index1}}:{{this.n}}{{#each this.tags}}<{{this}}>{{/each}}]{{/each}}';
        $r = $this->mini($tpl, ['u' => ['name' => 'Ana', 'admin' => false], 'rows' => [['n' => 'a', 'tags' => ['x', 'y']], ['n' => 'b', 'tags' => []]]]);
        $this->assertSame('<html>AnaB[1:a<x><y>][2:b]</html>', $r['html']);
    }

    public function testEachLeeVariablesExterioresYVacioNoImprime(): void
    {
        $r = $this->mini('{{#each r}}{{this.n}}{{outer}};{{/each}}{{#each e}}X{{/each}}', ['r' => [['n' => 1], ['n' => 2]], 'outer' => 'o', 'e' => []]);
        $this->assertSame('<html>1o;2o;</html>', $r['html']);
    }

    public function testVariableFaltanteEsVaciaYSeAnota(): void
    {
        file_put_contents($this->dir . '/t.html', 'a{{nope}}b{{#if opt}}x{{/if}}{{p.q}}');
        file_put_contents($this->dir . '/t.txt', '');
        file_put_contents($this->dir . '/t.subject.txt', 's');
        $t = new Templates($this->dir);
        $r = $t->render('t', []);
        $this->assertSame('<html>ab</html>', $r['html']);
        $this->assertSame(['nope', 'p.q'], $t->missing());
    }

    public function testGlobalesPorDefectoYMarcaOpcional(): void
    {
        $layout = $this->dir . '/_layout.html';
        file_put_contents($layout, '{{#if brand_name}}[{{brand_name}}]{{/if}}{{#if dry_run}}DRY{{/if}}{{{content}}}');
        file_put_contents($this->dir . '/t.html', 'x');
        file_put_contents($this->dir . '/t.txt', '');
        file_put_contents($this->dir . '/t.subject.txt', 's');
        $this->assertSame('x', (new Templates($this->dir))->render('t', [])['html']);
        $this->assertSame('[Acme]DRYx', (new Templates($this->dir, ['brand_name' => 'Acme', 'dry_run' => true]))->render('t', [])['html']);
    }

    public function testNombreInvalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Templates($this->dir))->render('../x', []);
    }

    /** @return array<string,array{0:string,1:array<string,mixed>}> */
    public static function plantillas(): array
    {
        $cand = [['n' => 1, 'name' => 'TPS <A>', 'address' => 'Valparaíso', 'badge' => 'más parecido'], ['n' => 2, 'name' => 'TLS', 'address' => 'San Antonio', 'badge' => '']];
        $duda = [
            'question_id' => 'D-0001', 'token' => 'ab12cd', 'title' => '¿Qué lugar es “TPSV”?', 'lead' => 'No lo reconozco.',
            'text' => 'TPSV', 'point_label' => 'Retiro en puerto', 'identifier' => 'ABCU1234567',
            'source' => 'cuerpo', 'context' => 'TARJETON: TPSV', 'message_subject' => 'Aviso de carga', 'message_sender' => 'ops@cliente.cl',
            'message_received' => '2026-11-10 10:00', 'also_count' => 3, 'can_remember' => true,
            'url' => 'https://x.test/?t=abc', 'ttl_hours' => 72, 'reply_to' => 'agente@x.test', 'mailto_subject' => 'Re%3A%20%5BDuda%20D-0001%5D',
            'proposal' => ['name' => 'TPS', 'address' => 'Valparaíso'], 'candidates' => $cand, 'field_label' => 'fecha de retiro', 'reason' => 'poca seguridad',
            'items' => [['label' => 'Retiro en puerto', 'value' => 'TPS'], ['label' => 'Fecha de retiro', 'value' => '2026-11-12']],
            'options' => [['n' => 1, 'name' => 'TPS', 'origin' => 'Decisión anterior'], ['n' => 2, 'name' => 'TLS', 'origin' => 'Regla actual']],
        ];
        $alert = ['badge' => 'Sin orden', 'badge_bg' => '#b91c1c', 'identifier' => 'ABCU1234567', 'order_id' => '123', 'what_happened' => 'No existe la orden.',
            'point_label' => 'Retiro en puerto', 'mail_value' => 'TPS', 'system_value' => 'TLS', 'source' => 'adjunto guia.pdf', 'what_to_do' => 'Crear la orden.',
            'feedback_ok_url' => 'https://x.test/ok', 'feedback_no_url' => 'https://x.test/no'];

        return [
            'duda_confirmacion' => ['duda_confirmacion', $duda],
            'duda_abierta' => ['duda_abierta', $duda],
            'duda_carga' => ['duda_carga', $duda],
            'duda_faltante' => ['duda_faltante', $duda],
            'duda_conflicto' => ['duda_conflicto', $duda],
            'aviso_hora' => ['aviso_hora', ['date' => '2026-11-17', 'hour' => '15:00', 'total' => 1, 'counts' => ['falta_en_sistema' => 1, 'lugar_vacio' => 0, 'distinto' => 0], 'alerts' => [$alert]]],
            'resumen_diario' => ['resumen_diario', [
                'date' => '2026-11-17',
                'totals' => ['mails' => 10, 'instructions' => 6, 'cases' => 8, 'automatic' => 5, 'questions_sent' => 2, 'questions_pending' => 1, 'alerts_sent' => 3, 'revalidated' => 1, 'aliases_new' => 2],
                'informatives' => [['identifier' => 'ABCU1234567', 'order_id' => '123', 'point_label' => 'Entrega al cliente', 'mail_value' => 'A', 'system_value' => 'B', 'source' => 'cuerpo', 'note' => 'cambio de plan']],
                'pending' => [['id' => 'D-0002', 'title' => 'Duda', 'age' => '5 h', 'url' => 'https://x.test/q']],
                'ai' => ['cost_usd' => '0.42', 'calls' => 7, 'tokens_in' => 1000, 'tokens_out' => 200], 'mcp' => ['calls' => 4, 'items' => 40],
            ]],
            'respuesta_recibida' => ['respuesta_recibida', $duda + ['answer_text' => 'TPS', 'answered_by' => 'Ana', 'effect' => 'Se reprocesan 3 cargas.', 'remembered' => true]],
            'enlace_vencido' => ['enlace_vencido', $duda + ['expired_at' => '2026-11-14 10:00']],
        ];
    }

    /** @param array<string,mixed> $vars */
    #[DataProvider('plantillas')]
    public function testCadaPlantillaRenderizaCompleta(string $name, array $vars): void
    {
        $t = new Templates(dirname(__DIR__) . '/plantillas', ['brand_name' => 'Acme', 'brand_footer' => 'Pie', 'base_url' => 'https://x.test', 'dry_run' => true]);
        $r = $t->render($name, $vars);

        $this->assertSame([], $t->missing(), "variables faltantes en {$name}");
        $this->assertNotSame('', $r['subject']);
        $this->assertStringNotContainsString("\n", $r['subject']);
        $this->assertStringNotContainsString("\r", $r['subject']);
        $this->assertStringNotContainsString('{{', $r['html'] . $r['text'] . $r['subject']);
        foreach (['html', 'text', 'subject'] as $k) {
            $this->assertStringNotContainsStringIgnoringCase('gotruck', $r[$k]);
            $this->assertStringNotContainsStringIgnoringCase('trg', $r[$k]);
        }
        // el contenido externo se escapa en HTML pero no en texto plano
        if (str_contains(json_encode($vars) ?: '', 'TPS <A>')) {
            $this->assertStringNotContainsString('TPS <A>', $r['html']);
        }
    }

    public function testAsuntoDeDudaTrAeIdYToken(): void
    {
        $t = new Templates(dirname(__DIR__) . '/plantillas');
        $vars = self::plantillas()['duda_abierta'][1];
        $this->assertSame('[Duda D-0001·ab12cd] ¿Qué lugar es “TPSV”?', $t->render('duda_abierta', $vars)['subject']);
    }

    public function testMarcaVaciaNoSeMuestra(): void
    {
        $t = new Templates(dirname(__DIR__) . '/plantillas');
        $html = $t->render('enlace_vencido', self::plantillas()['enlace_vencido'][1])['html'];
        $this->assertStringContainsString('Agente supervisor', $html);
        $this->assertStringNotContainsString('DRY RUN', $html);
    }
}
