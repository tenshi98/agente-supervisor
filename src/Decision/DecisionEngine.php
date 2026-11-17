<?php

declare(strict_types=1);

namespace Agente\Decision;

use Agente\Attachments\Iso6346;
use Agente\Resolve\Resolution;
use Agente\Rules\RuleSet;
use Agente\Text\Places;
use Agente\Understanding\Confidence;
use Agente\Understanding\Extraction;

/**
 * Paso 5: decidir con las reglas (reglas/decision.json) y la resolución de lugares ya hecha por PlaceResolver.
 * Lógica pura, sin BD ni red: recibe las resoluciones y las respuestas ya cargadas, devuelve qué quedó resuelto
 * y qué dudas hay (paso 6). Cada carga lleva sus decisiones (automatica, confirmacion, abierta...) que reglas/acciones.json
 * convierte en acciones.
 */
final class DecisionEngine
{
    public function __construct(private readonly RuleSet $rules)
    {
    }

    /**
     * @param array<string,Resolution> $resolutions cómo quedó cada texto de lugar (PlaceResolver), por Resolution::key(punto, texto)
     * @param array<string,array{tipo:string,valor?:string,place_id?:string,recordar?:bool}> $answers respuestas ya dadas: duda propia del correo (m<id>|...) o de un lugar (lugar|<texto>)
     */
    public function decide(Extraction $ex, int $messageId, array $resolutions, array $answers): DecisionResult
    {
        $min = (string) $this->rules->get('confianza.minima', 'media');

        $isProcessed = $ex->relevante && in_array($ex->tipo, $this->rules->processedTypes(), true);
        if (!$isProcessed) {
            // Correo que no se procesa. Si la IA dudó, se confirma con el destinatario antes de descartarlo.
            if (Confidence::atLeast($ex->confianza, $min)) {
                return new DecisionResult(DecisionResult::IRRELEVANT, [], "tipo={$ex->tipo}");
            }
            $canon = $this->canon($messageId, Doubt::KIND_CARGO, 'tipo', 'msg');
            $a = $answers[$canon]['tipo'] ?? null;
            if ($a === 'confirmar') {
                // El destinatario dice que sí es una instrucción: se sigue con lo extraído.
            } elseif ($a === 'ignorar') {
                return new DecisionResult(DecisionResult::IRRELEVANT, [], 'confirmado por el usuario');
            } else {
                $d = new Doubt(Doubt::KIND_CARGO, 'tipo', $canon, $ex->tipo, $ex->notas, 'correo', [], 'msg', flow: $ex->flujo);

                return new DecisionResult(DecisionResult::PENDING, [new ItemResult('msg', ['tipo' => $ex->tipo], [$d])]);
            }
        }

        $items = $ex->cargas === [] ? [$this->emptyItem($ex, $messageId, $answers)] : $this->cargaItems($ex, $messageId, $resolutions, $answers, $min);
        $pending = false;
        foreach ($items as $i) {
            if ($i->state() === ItemResult::PENDING) {
                $pending = true;
            }
        }
        $allIgnored = $items !== [] && count(array_filter($items, static fn (ItemResult $i): bool => $i->ignored)) === count($items);

        return new DecisionResult($pending ? DecisionResult::PENDING : ($allIgnored ? DecisionResult::IRRELEVANT : DecisionResult::DECIDED), $items);
    }

    /** @param array<string,array{tipo:string,valor?:string}> $answers */
    private function emptyItem(Extraction $ex, int $messageId, array $answers): ItemResult
    {
        $data = ['identificador' => '', 'flujo' => $ex->flujo, 'lugares' => [], 'fechas' => [], 'extras' => $ex->extras];
        $canon = $this->canon($messageId, Doubt::KIND_MISSING, 'identificador', 'msg');
        $a = $answers[$canon] ?? null;
        if ($a !== null && $a['tipo'] === 'ignorar') {
            return new ItemResult('msg', $data, [], true);
        }
        if ($a !== null && trim((string) ($a['valor'] ?? '')) !== '') {
            return new ItemResult('msg', $this->withIdentifier($data, (string) $a['valor']), []);
        }

        return new ItemResult('msg', $data, [new Doubt(Doubt::KIND_MISSING, 'identificador', $canon, '', $ex->notas, 'correo', [], 'msg', flow: $ex->flujo)]);
    }

    /**
     * @param array<string,Resolution> $resolutions
     * @param array<string,array{tipo:string,valor?:string}> $answers
     * @return list<ItemResult>
     */
    private function cargaItems(Extraction $ex, int $messageId, array $resolutions, array $answers, string $min): array
    {
        $required = $this->rules->requiredFields($ex->tipo);
        $items = [];
        $usedKeys = [];
        foreach ($ex->cargas as $idx => $c) {
            $key = $this->itemKey($c, $idx + 1);
            while (isset($usedKeys[$key])) {
                $key .= '_' . ($idx + 1);
            }
            $usedKeys[$key] = true;
            $items[] = $this->decideCarga($c, $key, $ex, $messageId, $resolutions, $answers, $required, $min);
        }

        return $items;
    }

    /** @param array<string,mixed> $c */
    private function itemKey(array $c, int $n): string
    {
        if ($c['contenedor'] !== '') {
            return $c['contenedor'];
        }
        if ($c['booking'] !== '') {
            return 'bk:' . $c['booking'];
        }
        if ($c['despacho'] !== '') {
            return 'ds:' . $c['despacho'];
        }

        return 'n' . $n;
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,Resolution> $resolutions
     * @param array<string,array{tipo:string,valor?:string}> $answers
     * @param list<string> $required
     */
    private function decideCarga(array $c, string $key, Extraction $ex, int $messageId, array $resolutions, array $answers, array $required, string $min): ItemResult
    {
        $doubts = [];
        $data = [
            'contenedor' => $c['contenedor'], 'booking' => $c['booking'], 'despacho' => $c['despacho'],
            'flujo' => $ex->flujo, 'lugares' => [], 'fechas' => $c['fechas'], 'confianza' => $c['confianza'], 'extras' => $ex->extras,
        ];

        // --- identificador (contenedor / booking / despacho) ---
        $hasId = $c['contenedor'] !== '' || $c['booking'] !== '' || $c['despacho'] !== '';
        if (!$hasId && in_array('identificador', $required, true)) {
            $canon = $this->canon($messageId, Doubt::KIND_MISSING, 'identificador', $key);
            $a = $answers[$canon] ?? null;
            if ($a !== null && $a['tipo'] === 'ignorar') {
                return new ItemResult($key, $data, [], true);
            }
            if ($a !== null && trim((string) ($a['valor'] ?? '')) !== '') {
                $data = $this->withIdentifier($data, (string) $a['valor']);
            } else {
                $doubts[] = new Doubt(Doubt::KIND_MISSING, 'identificador', $canon, '', $ex->notas, 'correo', [], $key, flow: $ex->flujo);
            }
        }

        // --- contenedor con dígito verificador inválido ---
        if ($c['contenedor'] !== '' && $this->rules->get('validaciones.contenedor_iso6346', true) && !Iso6346::isValid($c['contenedor'])) {
            $canon = $this->canon($messageId, Doubt::KIND_DATA, 'contenedor', $key);
            $a = $answers[$canon] ?? null;
            if ($a !== null && $a['tipo'] === 'ignorar') {
                $data['contenedor'] = '';
            } elseif ($a !== null && trim((string) ($a['valor'] ?? '')) !== '') {
                $data['contenedor'] = strtoupper(preg_replace('/[\s\-]+/', '', (string) $a['valor']) ?? '');
            } else {
                $doubts[] = new Doubt(Doubt::KIND_DATA, 'contenedor', $canon, $c['contenedor'], 'El código no cumple el dígito verificador ISO 6346', 'correo', [], $key, flow: $ex->flujo);
            }
        }

        // --- confianza de la carga ---
        if (!Confidence::atLeast($c['confianza'], $min)) {
            $canon = $this->canon($messageId, Doubt::KIND_CARGO, 'carga', $key);
            $a = $answers[$canon] ?? null;
            if ($a !== null && $a['tipo'] === 'ignorar') {
                return new ItemResult($key, $data, [], true);
            }
            if ($a !== null && trim((string) ($a['valor'] ?? '')) !== '') {
                $data['nota_usuario'] = trim((string) $a['valor']);
            } elseif ($a === null) {
                $doubts[] = new Doubt(Doubt::KIND_CARGO, 'carga', $canon, $key, $ex->notas, 'correo', [], $key, flow: $ex->flujo);
            }
        }

        // --- lugares (inicio / cliente / final) ---
        foreach (Extraction::ROLES as $role) {
            $data['lugares'][$role] = [];
            foreach ($c['lugares'][$role] as $m) {
                $r = $this->resolvePlace($m, $role, $key, $ex->flujo, $resolutions, $answers);
                if ($r['doubt'] instanceof Doubt) {
                    $doubts[] = $r['doubt'];
                    $data['lugares'][$role][] = ['texto' => $m['texto'], 'resuelto' => null, 'metodo' => 'pendiente', 'fuente' => $m['fuente']];
                } elseif ($r['place'] !== null) {
                    $data['lugares'][$role][] = ['texto' => $m['texto'], 'resuelto' => $r['place']['nombre'], 'place_id' => $r['place']['place_id'],
                        'metodo' => $r['place']['metodo'], 'zona' => $r['place']['zona'], 'marca' => $r['place']['marca'], 'fuente' => $m['fuente']];
                }
            }
        }

        // --- fechas con formato inválido ---
        if ($this->rules->get('validaciones.fechas_formato', true)) {
            foreach (Extraction::DATES as $k) {
                $v = $c['fechas'][$k];
                if ($v !== '' && !self::validDate($v)) {
                    $canon = $this->canon($messageId, Doubt::KIND_DATA, 'fecha_' . $k, $key);
                    $a = $answers[$canon] ?? null;
                    if ($a !== null && $a['tipo'] === 'ignorar') {
                        $data['fechas'][$k] = '';
                    } elseif ($a !== null && trim((string) ($a['valor'] ?? '')) !== '') {
                        $data['fechas'][$k] = trim((string) $a['valor']);
                    } else {
                        $doubts[] = new Doubt(Doubt::KIND_DATA, 'fecha_' . $k, $canon, $v, 'Fecha con formato no reconocido (se espera AAAA-MM-DD o AAAA-MM-DD HH:MM)', 'correo', [], $key);
                    }
                }
            }
        }

        // --- campos obligatorios ausentes (lugares.X / fechas.X) ---
        foreach ($required as $req) {
            if (preg_match('/^(lugares|fechas)\.(\w+)$/', $req, $m) !== 1) {
                continue;
            }
            [, $group, $name] = $m;
            $present = $group === 'lugares'
                ? ($c['lugares'][$name] ?? []) !== []
                : trim((string) ($c['fechas'][$name] ?? '')) !== '';
            if ($present) {
                continue;
            }
            $field = $group === 'lugares' ? $name : 'fecha_' . $name;
            $canon = $this->canon($messageId, Doubt::KIND_MISSING, $field, $key);
            $a = $answers[$canon] ?? null;
            if ($a !== null && $a['tipo'] === 'ignorar') {
                continue; // el usuario dice que ese dato no aplica
            }
            if ($a !== null && trim((string) ($a['valor'] ?? '')) !== '') {
                $v = trim((string) $a['valor']);
                if ($group === 'lugares') {
                    $data['lugares'][$name][] = ['texto' => $v, 'resuelto' => $v, 'metodo' => 'usuario', 'fuente' => 'respuesta'];
                } else {
                    $data['fechas'][$name] = $v;
                }
                continue;
            }
            $doubts[] = new Doubt(Doubt::KIND_MISSING, $field, $canon, '', $ex->notas, 'correo', [], $key);
        }

        return new ItemResult($key, $data, $doubts);
    }

    /**
     * @param array{texto:string,contexto:string,fuente:string} $m
     * @param array<string,Resolution> $resolutions
     * @param array<string,array{tipo:string,valor?:string,place_id?:string}> $answers
     * @return array{place:?array{nombre:string,place_id:?string,metodo:string,zona:?string,marca:?string},doubt:?Doubt}
     */
    private function resolvePlace(array $m, string $role, string $itemKey, string $flow, array $resolutions, array $answers): array
    {
        $norm = Places::normalize($m['texto']);
        if ($norm === '') {
            return ['place' => null, 'doubt' => null];
        }
        $canon = 'lugar|' . $norm;

        // Respuesta ya dada por un destinatario (con o sin «recordar»): vale para este texto.
        $a = $answers[$canon] ?? null;
        if ($a !== null) {
            if (($a['tipo'] ?? '') === 'ignorar') {
                return ['place' => null, 'doubt' => null];
            }
            $v = trim((string) ($a['valor'] ?? ''));
            if ($v !== '') {
                return ['place' => ['nombre' => $v, 'place_id' => ($a['place_id'] ?? null) ?: null, 'metodo' => 'usuario', 'zona' => null, 'marca' => null], 'doubt' => null];
            }
        }

        $r = $resolutions[Resolution::key($role, $m['texto'])] ?? new Resolution(Resolution::OPEN, 'ninguno');
        if ($r->level === Resolution::AUTOMATIC) {
            if ($r->ignored) {
                return ['place' => null, 'doubt' => null];
            }

            return ['place' => ['nombre' => (string) ($r->name ?? $m['texto']), 'place_id' => $r->placeId, 'metodo' => $r->method, 'zona' => $r->zone, 'marca' => $r->brand], 'doubt' => null];
        }

        if ($r->conflict) {
            $opts = array_map(static fn (string $n): array => ['place_id' => '', 'nombre' => $n, 'direccion' => '', 'similitud' => 0], $r->conflictOptions);

            return ['place' => null, 'doubt' => new Doubt(Doubt::KIND_CONFLICT, $role, $canon, $m['texto'], $m['contexto'], $m['fuente'], $opts, $itemKey, flow: $flow)];
        }
        $level = $r->level === Resolution::CONFIRM && $r->proposalPlaceId !== null ? Doubt::LEVEL_CONFIRM : Doubt::LEVEL_OPEN;

        return ['place' => null, 'doubt' => new Doubt(Doubt::KIND_PLACE, $role, $canon, $m['texto'], $m['contexto'], $m['fuente'], $r->candidates, $itemKey, $level, $r->proposalPlaceId, $r->proposalConf, $flow)];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function withIdentifier(array $data, string $value): array
    {
        $v = trim($value);
        $compact = strtoupper((string) preg_replace('/[\s\-]+/', '', $v));
        if (preg_match('/^[A-Z]{3}[UJZ]\d{7}$/', $compact) === 1) {
            $data['contenedor'] = $compact;
        } elseif (preg_match('/^\d{5,}$/', $v) === 1) {
            $data['despacho'] = $v;
        } else {
            $data['booking'] = strtoupper($v);
        }
        $data['identificador'] = $v;

        return $data;
    }

    private function canon(int $messageId, string $kind, string $field, string $itemKey): string
    {
        return sprintf('m%d|%s|%s|%s', $messageId, $kind, $field, $itemKey);
    }

    private static function validDate(string $v): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?$/', $v, $m) !== 1) {
            return false;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return false;
        }

        return !isset($m[4]) || ((int) $m[4] < 24 && (int) $m[5] < 60);
    }
}
