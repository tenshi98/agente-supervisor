<?php

declare(strict_types=1);

/**
 * Única entrada web del agente. Paso 7: el usuario autorizado abre el enlace de UN SOLO USO que recibió por correo,
 * toma la decisión y, si marca «recordar», el agente la guarda como equivalencia. Las otras rutas públicas (aviso.php, panel.php, gmail.php) tienen su propio enlace firmado.
 *
 * Seguridad: enlace firmado (HMAC) con vencimiento; solo decisores activos del tenant; el uso único se aplica en la BD
 * con una actualización atómica; todo texto que viene de correos se escapa; sin JavaScript; CSP estricta.
 */

use Agente\Notify\QuestionText;
use Agente\Persistence\Actions;
use Agente\Persistence\Aliases;
use Agente\Persistence\Messages;
use Agente\Persistence\Questions;
use Agente\Persistence\Recipients;
use Agente\Pipeline\AnswerService;
use Agente\Rules\RuleSet;
use Agente\Text\Places;
use Agente\Support\Config;
use Agente\Support\Db;
use Agente\Support\LinkSigner;
use Agente\Support\Logger;

require dirname(__DIR__) . '/vendor/autoload.php';

$base = dirname(__DIR__);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$page = static function (int $status, string $title, string $body) use ($e): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $e($title) . '</title><style>'
        . 'body{font:16px/1.5 system-ui,sans-serif;max-width:680px;margin:2rem auto;padding:0 1rem;color:#222}'
        . '.box{border:1px solid #ddd;border-radius:8px;padding:1rem 1.25rem;background:#fafafa;margin:1rem 0}'
        . 'dt{font-weight:600;margin-top:.5rem}dd{margin:0}label{display:block;padding:.45rem .6rem;border:1px solid #ddd;border-radius:6px;margin:.4rem 0;cursor:pointer}'
        . 'input[type=text]{width:100%;box-sizing:border-box;padding:.5rem;margin-top:.4rem;font:inherit}'
        . 'button{background:#ffab23;border:0;border-radius:6px;padding:.7rem 1.4rem;font:inherit;font-weight:700;cursor:pointer}'
        . '.err{background:#fdecea;border:1px solid #f5c2c0;color:#8a1c14;border-radius:6px;padding:.6rem .8rem}.mut{color:#666;font-size:.9rem}'
        . '</style><h1>' . $e($title) . '</h1>' . $body . '</html>';
    exit;
};

$token = (string) ($_GET['t'] ?? '');
if ($token === '') {
    $page(404, 'No encontrado', '<p>Esta página solo se usa con un enlace enviado por correo.</p>');
}

try {
    $config = Config::load($base);
    $log = new Logger($base . '/logs/web.log');
    $claims = (new LinkSigner($config->require('AGENT_LINK_SECRET')))->verify($token);
    if ($claims === null) {
        $page(403, 'Enlace inválido', '<p>El enlace no es válido o ya venció. Se enviará uno nuevo si la duda sigue abierta.</p>');
    }

    $db = Db::agent($config);
    $questions = new Questions($db);

    // Solo decide quien está en la lista de usuarios autorizados del tenant.
    $recipient = (new Recipients($db))->activeDecider($claims['r'], $claims['t']);
    if ($recipient === null) {
        $log->warn('Enlace con destinatario no autorizado: ' . $claims['r']);
        $page(403, 'Sin autorización', '<p>Tu usuario no está autorizado para decidir.</p>');
    }

    $q = $questions->get($claims['q'], $claims['t']);
    if ($q === null) {
        $page(404, 'Duda no encontrada', '<p>No existe esa duda.</p>');
    }
    if ((int) $q['link_used'] === 1 || $q['status'] === 'respondida') {
        $page(410, 'Duda ya respondida', '<p>Esta duda ya fue respondida; el enlace es de un solo uso.</p>');
    }

    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Solo formularios de este mismo sitio.
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && (parse_url($origin, PHP_URL_HOST) ?? '') !== explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]) {
            $page(403, 'Solicitud rechazada', '<p>El formulario debe enviarse desde esta misma página.</p>');
        }
        $rules = RuleSet::load($config->get('RULES_DIR') ?? $base . '/reglas');
        $answer = AnswerService::parse($q, $_POST);
        if (is_string($answer)) {
            $error = $answer;
        } else {
            $service = new AnswerService($db, $questions, new Aliases($db), new Messages($db), $rules, new Actions($db));
            if (!$service->apply($q, (int) $recipient['recipient_id'], $answer)) {
                $page(410, 'Duda ya respondida', '<p>Otra persona respondió esta duda o el enlace ya se usó.</p>');
            }
            $log->info("Duda D-{$q['question_id']} respondida por {$recipient['email']}: {$answer['tipo']}");
            $page(200, 'Decisión registrada', '<p>Gracias. Guardé tu decisión.' . (!empty($answer['recordar']) ? ' La recordaré como equivalencia para casos futuros.' : '') . '</p>'
                . '<p class="mut">El correo que estaba pendiente se procesará de nuevo en la próxima corrida del agente.</p>');
        }
    }

    // ---- formulario ----
    $d = QuestionText::describe($q);
    $level = (string) ($q['level'] ?? '');
    $act = QuestionText::actions((string) $q['kind'], (string) $q['field'], $level);
    $cands = AnswerService::candidates($q);
    // «Recordar» solo para lugares con texto no genérico (una respuesta equivocada guardada propagaría errores).
    $rules = RuleSet::load($config->get('RULES_DIR') ?? $base . '/reglas');
    $generic = in_array(Places::normalize((string) $q['text']), array_map([Places::class, 'normalize'], $rules->genericPlaces()), true);
    $canRemember = $act['remember'] && !$generic;
    $msg = $questions->sourceMessage($q['message_id'] === null ? null : (int) $q['message_id']);

    $info = '<dl>';
    if ((string) ($q['source'] ?? '') !== '' || (string) ($q['context'] ?? '') !== '') {
        $info .= '<dt>Dónde lo dice</dt><dd>' . $e($q['source']) . ((string) $q['context'] !== '' ? ' — “' . $e($q['context']) . '”' : '') . '</dd>';
    }
    if ($msg !== null) {
        $info .= '<dt>Correo</dt><dd>' . $e($msg['subject']) . '<br><span class="mut">' . $e($msg['sender']) . ' · ' . $e($msg['received']) . ' UTC</span></dd>';
    }
    $info .= '</dl>';

    $opts = '';
    if ($act['confirm'] !== null && (string) $q['kind'] === 'lugar') {
        // Duda de un clic: se muestra la propuesta y basta confirmarla.
        $pid = (string) ($q['proposal_place_id'] ?? '');
        foreach ($cands as $c) {
            if ($pid !== '' && $c['place_id'] === $pid) {
                $opts .= '<label><input type="radio" name="accion" value="confirmar" required checked> <b>' . $e($act['confirm']) . ':</b> '
                    . $e($c['nombre']) . ($c['direccion'] !== '' ? ' <span class="mut">(' . $e($c['direccion']) . ')</span>' : '') . '</label>';
            }
        }
    }
    foreach ($cands as $i => $c) {
        $opts .= '<label><input type="radio" name="accion" value="cand:' . $i . '" required> ' . $e($c['nombre'])
            . ($c['direccion'] !== '' ? ' <span class="mut">(' . $e($c['direccion']) . ')</span>' : '') . '</label>';
    }
    if ($act['confirm'] !== null && (string) $q['kind'] !== 'lugar') {
        $opts .= '<label><input type="radio" name="accion" value="confirmar" required> ' . $e($act['confirm']) . '</label>';
    }
    if ($act['value'] !== null) {
        $opts .= '<label><input type="radio" name="accion" value="valor" required> ' . $e($act['value'])
            . '<input type="text" name="valor" maxlength="255" autocomplete="off"></label>';
    }
    $opts .= '<label><input type="radio" name="accion" value="ignorar" required> ' . $e($act['ignore']) . '</label>';

    $page(200, 'Duda D-' . str_pad((string) $q['question_id'], 4, '0', STR_PAD_LEFT) . ' · ' . $d['title'],
        '<p>' . $e($d['lead']) . '</p><div class="box">' . $info . '</div>'
        . ($error !== '' ? '<p class="err">' . $e($error) . '</p>' : '')
        . '<form method="post" action="?t=' . $e(rawurlencode($token)) . '">' . $opts
        . ($canRemember ? '<label><input type="checkbox" name="recordar" value="1"> Recordar siempre esta equivalencia</label>' : '')
        . '<p><button type="submit">Guardar decisión</button></p></form>'
        . '<p class="mut">' . ($canRemember ? 'Con «recordar», el agente no volverá a preguntar por este texto.' : 'Esta respuesta vale solo para este caso.') . '</p>');
} catch (Throwable $ex) {
    (new Logger($base . '/logs/web.log'))->error($ex::class . ': ' . $ex->getMessage());
    $page(500, 'Error', '<p>No se pudo procesar la solicitud.</p>');
}
