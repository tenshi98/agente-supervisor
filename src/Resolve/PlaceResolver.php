<?php

declare(strict_types=1);

namespace Agente\Resolve;

use Agente\Mcp\CatalogClient;
use Agente\Rules\RuleSet;
use Agente\Text\Places;

/**
 * Resuelve el texto de cada lugar contra el catálogo del sistema externo (por MCP), en este orden:
 *   equivalencia guardada -> texto genérico (no se pregunta) -> coincidencia exacta o parecida
 *   -> la IA elige entre candidatos -> cliente con varias sedes (se verifica por marca).
 * La IA nunca inventa un lugar: elige un candidato o dice «ninguno».
 *
 * Ahorro de consultas: todos los textos de todos los correos de la corrida viajan juntos en UNA llamada al catálogo
 * y, si hace falta, en UNA llamada a la IA.
 */
final class PlaceResolver
{
    public function __construct(
        private readonly RuleSet $rules,
        private readonly ?CatalogClient $catalog,
        private readonly ?PlaceChooser $chooser = null,
    ) {
    }

    /**
     * @param list<array{texto:string,contexto?:string,rol:string,flujo?:string}> $mentions
     * @param array<string,array{value:string,place_id:?string,source:string,review?:bool}> $aliases equivalencias guardadas
     * @return array<string,Resolution> por Resolution::key(rol, texto)
     */
    public function resolve(int $tenantId, array $mentions, array $aliases, string $process = 'resolve'): array
    {
        $out = [];
        $pending = [];
        $generic = array_map([Places::class, 'normalize'], $this->rules->genericPlaces());
        $addr = $this->rules->addressRules();
        $brands = $this->rules->brands();

        foreach ($mentions as $m) {
            $norm = Places::normalize($m['texto']);
            $key = Resolution::key($m['rol'], $m['texto']);
            if ($norm === '' || isset($out[$key]) || isset($pending[$key])) {
                continue;
            }

            // 1) equivalencia guardada (y reglas de dirección: «DPW» a secas = Puerto Central)
            $alias = $aliases[$norm] ?? null;
            $rule = $addr[$norm] ?? null;
            if ($alias !== null && $rule !== null && $alias['source'] === 'usuario' && $alias['value'] !== $rule && $alias['value'] !== \Agente\Persistence\Aliases::IGNORE) {
                // Una decisión guardada contradice lo que hoy dice una regla: se pregunta cuál vale.
                $out[$key] = new Resolution(Resolution::OPEN, 'conflicto', conflict: true, conflictOptions: [$alias['value'], $rule]);
                continue;
            }
            if ($alias !== null) {
                $out[$key] = $alias['value'] === \Agente\Persistence\Aliases::IGNORE
                    ? new Resolution(Resolution::AUTOMATIC, 'equivalencia', ignored: true)
                    : new Resolution(Resolution::AUTOMATIC, 'equivalencia', $alias['place_id'], $alias['value']);
                continue;
            }
            if ($rule !== null) {
                $out[$key] = new Resolution(Resolution::AUTOMATIC, 'equivalencia', null, $rule);
                continue;
            }

            // 2) texto genérico de ciudad/zona: se verifica por zona, nunca se pregunta
            if (in_array($norm, $generic, true)) {
                $out[$key] = new Resolution(Resolution::AUTOMATIC, 'generico', null, $m['texto'], zone: $this->rules->zoneOf($norm));
                continue;
            }
            $pending[$key] = $m;
        }

        if ($pending === []) {
            return $out;
        }
        if ($this->catalog === null) {
            foreach ($pending as $key => $m) {
                $out[$key] = new Resolution(Resolution::OPEN, 'ninguno');
            }

            return $out;
        }

        // 3) catálogo del sistema externo: una sola llamada para todos los textos pendientes
        $items = [];
        foreach ($pending as $key => $m) {
            $items[] = ['ref' => $key, 'texto' => $m['texto'], 'rol' => $m['rol'], 'flujo' => (string) ($m['flujo'] ?? 'desconocido')];
        }
        $res = $this->catalog->resolve($tenantId, $items, $process);

        $min = (int) $this->rules->get('lugares.similitud_minima_candidato', 55);
        $max = max(1, (int) $this->rules->get('lugares.max_candidatos', 4));
        $exact = (int) $this->rules->get('lugares.umbral_exacto', 99);
        $near = (int) $this->rules->get('lugares.umbral_parecido', 90);
        $lead = (int) $this->rules->get('lugares.ventaja_parecido', 8);
        $proposal = (int) $this->rules->get('lugares.umbral_propuesta', 75);
        $exclude = array_map('strval', (array) $this->rules->get('lugares.cliente_excluye_categorias', []));
        $onlyActive = (bool) $this->rules->get('lugares.solo_activos', true);

        $toChoose = [];
        $cands = [];
        foreach ($pending as $key => $m) {
            $r = $res[$key] ?? ['status' => 'error'];
            $status = (string) ($r['status'] ?? 'error');
            if ($status === 'error') {
                $out[$key] = new Resolution(Resolution::DEFER, 'ninguno'); // sistema no disponible: no se decide ni se pregunta
                continue;
            }
            $norm = Places::normalize($m['texto']);
            if (!empty($r['es_generico'])) {
                $out[$key] = new Resolution(Resolution::AUTOMATIC, 'generico', null, $m['texto'], zone: $this->rules->zoneOf($norm));
                continue;
            }
            $brand = isset($r['marca']) && $r['marca'] !== null && $r['marca'] !== '' ? (string) $r['marca'] : null;
            if ($brand === null && $m['rol'] === 'cliente' && in_array($norm, $brands, true)) {
                $brand = $norm;
            }
            if ($brand !== null && $m['rol'] === 'cliente') {
                $out[$key] = new Resolution(Resolution::AUTOMATIC, 'marca', null, $brand, brand: $brand);
                continue;
            }

            $list = [];
            foreach ((array) ($r['candidatos'] ?? []) as $c) {
                if (!is_array($c) || ($onlyActive && array_key_exists('activo', $c) && !$c['activo'])) {
                    continue;
                }
                if ($m['rol'] === 'cliente' && in_array((string) ($c['categoria'] ?? ''), $exclude, true)) {
                    continue; // un puerto o depósito no puede ser el «cliente»
                }
                if ((int) ($c['similitud'] ?? 0) < $min) {
                    continue;
                }
                $list[] = [
                    'place_id' => (string) ($c['place_id'] ?? ''), 'nombre' => (string) ($c['nombre'] ?? ''),
                    'direccion' => (string) ($c['direccion'] ?? ''), 'similitud' => (int) ($c['similitud'] ?? 0),
                ];
            }
            usort($list, static fn (array $a, array $b): int => $b['similitud'] <=> $a['similitud']);
            $list = array_slice($list, 0, $max);
            $cands[$key] = $list;

            if ($list === []) {
                $out[$key] = new Resolution(Resolution::OPEN, 'ninguno');
                continue;
            }
            $top = $list[0];
            $second = $list[1]['similitud'] ?? 0;
            if ($top['similitud'] >= $exact && $second < $exact) {
                $out[$key] = new Resolution(Resolution::AUTOMATIC, 'exacto', $top['place_id'], $top['nombre'], $list);
            } elseif ($top['similitud'] >= $near && $top['similitud'] - $second >= $lead) {
                $out[$key] = new Resolution(Resolution::AUTOMATIC, 'parecido', $top['place_id'], $top['nombre'], $list);
            } else {
                $toChoose[$key] = $m;
            }
        }

        // 4) la IA elige entre candidatos (una sola llamada para todos) o dice «ninguno»
        $picked = [];
        if ($toChoose !== [] && $this->chooser !== null) {
            $in = [];
            foreach ($toChoose as $key => $m) {
                $in[] = ['ref' => $key, 'texto' => $m['texto'], 'contexto' => (string) ($m['contexto'] ?? ''), 'rol' => $m['rol'],
                    'flujo' => (string) ($m['flujo'] ?? 'desconocido'), 'candidatos' => $cands[$key]];
            }
            $picked = $this->chooser->choose($tenantId, $in);
        }
        foreach ($toChoose as $key => $m) {
            $list = $cands[$key];
            if ($this->chooser === null) {
                $out[$key] = $list[0]['similitud'] >= $proposal
                    ? new Resolution(Resolution::CONFIRM, 'parecido', null, null, $list, $list[0]['place_id'], 'media')
                    : new Resolution(Resolution::OPEN, 'parecido', null, null, $list);
                continue;
            }
            $p = $picked[$key] ?? ['place_id' => null, 'confianza' => 'baja'];
            $chosen = null;
            foreach ($list as $c) {
                if ($c['place_id'] === $p['place_id']) {
                    $chosen = $c;
                }
            }
            if ($chosen === null) {
                $out[$key] = new Resolution(Resolution::OPEN, 'ia', null, null, $list);
            } elseif ($p['confianza'] === 'alta') {
                $out[$key] = new Resolution(Resolution::AUTOMATIC, 'ia', $chosen['place_id'], $chosen['nombre'], $list);
            } elseif ($p['confianza'] === 'media') {
                $out[$key] = new Resolution(Resolution::CONFIRM, 'ia', null, null, $list, $chosen['place_id'], 'media');
            } else {
                $out[$key] = new Resolution(Resolution::OPEN, 'ia', null, null, $list, $chosen['place_id'], 'baja');
            }
        }

        return $out;
    }
}
