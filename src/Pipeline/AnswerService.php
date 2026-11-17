<?php

declare(strict_types=1);

namespace Agente\Pipeline;

use Agente\Decision\Doubt;
use Agente\Persistence\Actions;
use Agente\Persistence\Aliases;
use Agente\Persistence\Messages;
use Agente\Persistence\Questions;
use Agente\Rules\RuleSet;
use Agente\Text\Places;
use PDO;

/**
 * Paso 7: toma la decisión del destinatario (enlace o correo), la guarda y deja los correos pendientes listos para
 * volver a procesarse (la próxima corrida decide de nuevo sin repetir la lectura de la IA).
 * Con «recordar» y un texto no genérico, la respuesta se vuelve una equivalencia permanente.
 */
final class AnswerService
{
    public function __construct(
        private readonly PDO $db,
        private readonly Questions $questions,
        private readonly Aliases $aliases,
        private readonly Messages $messages,
        private readonly ?RuleSet $rules = null,
        private readonly ?Actions $actions = null,
    ) {
    }

    /**
     * Convierte lo enviado en el formulario en una respuesta válida.
     *
     * @param array<string,mixed> $question fila de ndb_agent_questions
     * @param array<string,mixed> $post campos del formulario: accion, valor, recordar
     * @return array{tipo:string,valor?:string,place_id?:string,recordar?:bool}|string respuesta, o mensaje de error en español
     */
    public static function parse(array $question, array $post): array|string
    {
        $accion = (string) ($post['accion'] ?? '');
        $kind = (string) $question['kind'];
        $remember = !empty($post['recordar']) || str_ends_with($accion, ':recordar');
        $accion = preg_replace('/:recordar$/', '', $accion) ?? $accion;
        $cands = self::candidates($question);

        if ($accion === 'ignorar') {
            return ['tipo' => 'ignorar'];
        }
        if ($accion === 'confirmar') {
            if ($kind === Doubt::KIND_CARGO) {
                return ['tipo' => 'confirmar'];
            }
            // Duda de un clic: se confirma la propuesta.
            $pid = (string) ($question['proposal_place_id'] ?? '');
            foreach ($cands as $c) {
                if ($pid !== '' && $c['place_id'] === $pid) {
                    return ['tipo' => 'valor', 'valor' => $c['nombre'], 'place_id' => $c['place_id'], 'recordar' => $remember];
                }
            }

            return 'Opción no válida.';
        }
        if (preg_match('/^cand:(\d+)$/', $accion, $m) === 1) {
            $i = (int) $m[1];

            return isset($cands[$i])
                ? ['tipo' => 'valor', 'valor' => $cands[$i]['nombre'], 'place_id' => $cands[$i]['place_id'], 'recordar' => $remember]
                : 'Opción no válida.';
        }
        if ($accion === 'valor') {
            $v = trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', (string) ($post['valor'] ?? '')));
            if ($v === '') {
                return 'Escribe un valor o elige otra opción.';
            }
            if (mb_strlen($v) > 255) {
                return 'El texto es demasiado largo (máximo 255 caracteres).';
            }

            return ['tipo' => 'valor', 'valor' => $v, 'recordar' => $remember];
        }

        return 'Elige una opción.';
    }

    /**
     * Interpreta la respuesta por correo (alternativa al enlace): la primera línea con contenido del cuerpo.
     * «1» / «1 R» elige el candidato 1 o confirma la propuesta (con R, además se recuerda); «S» o «SI» confirma la propuesta (S R: y recuerda);
     * «N» o «0» ignora; cualquier otro texto es un valor escrito.
     *
     * @param array<string,mixed> $question
     * @return array{tipo:string,valor?:string,place_id?:string,recordar?:bool}|string
     */
    public static function parseMail(array $question, string $body): array|string
    {
        $line = '';
        foreach (preg_split('/\R/u', $body) ?: [] as $l) {
            $l = trim($l);
            if ($l === '') {
                continue;
            }
            if (str_starts_with($l, '>') || preg_match('/^(el .* escribi|on .* wrote|de:|from:|-----)/iu', $l) === 1) {
                break; // texto citado
            }
            $line = $l;
            break;
        }
        if ($line === '') {
            return 'La respuesta está vacía.';
        }
        if (preg_match('/^(\d+)\s*(r)?$/i', $line, $m) === 1) {
            $n = (int) $m[1];
            if ($n === 0) {
                return ['tipo' => 'ignorar'];
            }

            $oneClick = (string) ($question['level'] ?? '') === Doubt::LEVEL_CONFIRM || (string) $question['kind'] === Doubt::KIND_CARGO;

            // En una duda de un clic o de carga, «1» confirma la propuesta; en las abiertas elige el candidato N.
            return self::parse($question, ['accion' => $oneClick && $n === 1 ? 'confirmar' : 'cand:' . ($n - 1), 'recordar' => ($m[2] ?? '') !== '']);
        }
        if (preg_match('/^(s|si|sí|ok)\s*(r)?$/iu', $line, $m) === 1) {
            return self::parse($question, ['accion' => 'confirmar', 'recordar' => ($m[2] ?? '') !== '']);
        }
        if (preg_match('/^(n|no|ignorar)$/iu', $line) === 1) {
            return ['tipo' => 'ignorar'];
        }

        return self::parse($question, ['accion' => 'valor', 'valor' => $line]);
    }

    /** @param array<string,mixed> $q @return list<array{place_id:string,nombre:string,direccion:string,similitud:int}> */
    public static function candidates(array $q): array
    {
        $raw = json_decode((string) ($q['candidates_json'] ?? '[]'), true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $c) {
            if (is_string($c)) {
                $c = ['nombre' => $c];
            }
            if (is_array($c) && trim((string) ($c['nombre'] ?? '')) !== '') {
                $out[] = ['place_id' => (string) ($c['place_id'] ?? ''), 'nombre' => (string) $c['nombre'], 'direccion' => (string) ($c['direccion'] ?? ''), 'similitud' => (int) ($c['similitud'] ?? 0)];
            }
        }

        return $out;
    }

    /**
     * Registra la respuesta de forma atómica.
     *
     * @param array<string,mixed> $question
     * @param array{tipo:string,valor?:string,place_id?:string,recordar?:bool} $answer
     * @return bool false si la duda ya no estaba abierta (otra persona respondió o el enlace ya se usó)
     */
    public function apply(array $question, int $recipientId, array $answer, string $via = 'enlace'): bool
    {
        $qid = (int) $question['question_id'];
        $tenant = (int) $question['tenant_id'];
        $kind = (string) $question['kind'];

        $this->db->beginTransaction();
        try {
            // «Recordar» solo para lugares con texto no genérico: una respuesta equivocada guardada propaga errores.
            $isPlace = in_array($kind, [Doubt::KIND_PLACE, Doubt::KIND_CONFLICT], true) && str_starts_with((string) $question['canon'], 'lugar|');
            $norm = $isPlace ? substr((string) $question['canon'], strlen('lugar|')) : '';
            $remember = !empty($answer['recordar']) || $answer['tipo'] === 'ignorar' && !empty($answer['recordar']);
            if ($remember && $isPlace && $this->isGeneric($norm)) {
                $answer['recordar'] = false;
                $remember = false;
            }
            if (!$this->questions->answer($qid, $recipientId, $answer, $via)) {
                $this->db->rollBack();

                return false;
            }
            if ($isPlace && $remember) {
                $value = $answer['tipo'] === 'ignorar' ? Aliases::IGNORE : (string) ($answer['valor'] ?? '');
                if ($value !== '') {
                    $this->aliases->remember($tenant, $norm, $value, ($answer['place_id'] ?? '') ?: null, $kind === Doubt::KIND_CONFLICT ? 'regla' : 'usuario', $qid, $recipientId);
                    $this->actions?->once($tenant, 'respuesta_' . ($answer['tipo'] === 'ignorar' ? 'ignorar' : 'valor'), 'guardar_equivalencia', 'q' . $qid);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        // Los correos que esperaban esta decisión vuelven a la cola; se decide de nuevo con lo guardado.
        $this->messages->reopen($this->questions->messageIds($qid));
        $this->actions?->once($tenant, 'respuesta_' . ($answer['tipo'] === 'ignorar' ? 'ignorar' : 'valor'), 'reprocesar_pendientes', 'q' . $qid);

        return true;
    }

    private function isGeneric(string $norm): bool
    {
        if ($this->rules === null || (bool) $this->rules->get('equivalencias.recordar_genericos', false)) {
            return false;
        }
        foreach ($this->rules->genericPlaces() as $g) {
            if (Places::normalize($g) === $norm) {
                return true;
            }
        }

        return false;
    }
}
