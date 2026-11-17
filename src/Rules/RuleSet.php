<?php

declare(strict_types=1);

namespace Agente\Rules;

use RuntimeException;

/**
 * Reglas editables del agente (carpeta reglas/):
 *  - *.md           texto que se envía a la IA como instrucciones (se cachea: es igual en cada llamada).
 *  - decision.json  reglas que aplica PHP para decidir si un dato está resuelto o hay que preguntar.
 *  - lugares.json   textos genéricos (por zona), marcas, reglas de dirección y equivalencias semilla.
 *  - acciones.json  qué acción produce cada decisión (opcional).
 * Se leen en cada corrida: editar y guardar basta.
 */
final class RuleSet
{
    /** @param array<string,mixed> $decision @param array<string,mixed> $places */
    public function __construct(
        public readonly string $prompt,
        private readonly array $decision,
        private readonly array $places,
        private readonly array $actions = [],
    ) {
    }

    public static function load(string $dir): self
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("No existe la carpeta de reglas: {$dir}");
        }
        $md = glob(rtrim($dir, '/') . '/*.md') ?: [];
        sort($md);
        $prompt = '';
        foreach ($md as $f) {
            $prompt .= trim((string) file_get_contents($f)) . "\n\n";
        }
        if (trim($prompt) === '') {
            throw new RuntimeException("La carpeta de reglas no tiene archivos .md: {$dir}");
        }

        return new self(trim($prompt), self::json($dir . '/decision.json'), self::json($dir . '/lugares.json', true), self::json($dir . '/acciones.json', true));
    }

    /** @return array<string,mixed> */
    private static function json(string $file, bool $optional = false): array
    {
        if (!is_file($file)) {
            if ($optional) {
                return [];
            }
            throw new RuntimeException("Falta el archivo de reglas: {$file}");
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new RuntimeException("JSON inválido en {$file}: " . json_last_error_msg());
        }

        return $data;
    }

    /** Acceso por ruta con puntos: get('confianza.minima'). */
    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->decision;
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }

        return $node;
    }

    /** @return list<string> */
    public function requiredFields(string $tipo): array
    {
        $list = $this->get('campos_obligatorios.' . $tipo, []);

        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }

    /** @return list<string> */
    public function processedTypes(): array
    {
        $l = $this->get('tipos_que_se_procesan', ['instruccion_transporte']);

        return is_array($l) ? array_values(array_map('strval', $l)) : [];
    }

    /** @return list<string> textos genéricos ya normalizados por quien los consulta */
    public function genericPlaces(): array
    {
        $g = $this->places['genericos'] ?? [];

        return is_array($g) ? array_values(array_map('strval', $g)) : [];
    }

    /** @return list<array{nombre:string,alias:list<string>}> */
    public function knownPlaces(): array
    {
        $out = [];
        foreach (($this->places['lugares'] ?? []) as $p) {
            if (is_array($p) && isset($p['nombre']) && trim((string) $p['nombre']) !== '') {
                $out[] = [
                    'nombre' => trim((string) $p['nombre']),
                    'alias' => array_values(array_map('strval', is_array($p['alias'] ?? null) ? $p['alias'] : [])),
                ];
            }
        }

        return $out;
    }

    /**
     * Reglas de dirección: texto normalizado => nombre del lugar («dpw» a secas es Puerto Central).
     *
     * @return array<string,string>
     */
    public function addressRules(): array
    {
        $out = [];
        foreach (($this->places['reglas_de_direccion'] ?? []) as $k => $v) {
            if (is_string($k) && !str_starts_with($k, '_') && is_string($v) && $v !== '') {
                $out[\Agente\Text\Places::normalize($k)] = $v;
            }
        }

        return $out;
    }

    /**
     * Equivalencias semilla (texto normalizado => nombre del lugar) para cargar con tools/seed_equivalences.php.
     *
     * @return array<string,string>
     */
    public function seedAliases(): array
    {
        $out = [];
        foreach (($this->places['equivalencias_semilla'] ?? []) as $k => $v) {
            if (is_string($k) && is_string($v) && $v !== '') {
                $out[\Agente\Text\Places::normalize($k)] = $v;
            }
        }

        return $out;
    }

    /** Zona de un texto genérico ya normalizado, o null si no tiene zona. */
    public function zoneOf(string $norm): ?string
    {
        $z = $this->places['zona_de_generico'][$norm] ?? null;

        return is_string($z) && $z !== '' ? $z : null;
    }

    /** @return array<string,array{lat:float,lng:float,radio_km:float}> */
    public function zones(): array
    {
        $out = [];
        foreach (($this->places['zonas'] ?? []) as $k => $z) {
            if (is_array($z) && isset($z['lat'], $z['lng'], $z['radio_km'])) {
                $out[(string) $k] = ['lat' => (float) $z['lat'], 'lng' => (float) $z['lng'], 'radio_km' => (float) $z['radio_km']];
            }
        }

        return $out;
    }

    /** @return list<string> marcas (clientes con varias sedes) normalizadas */
    public function brands(): array
    {
        $m = $this->places['marcas'] ?? [];

        return is_array($m) ? array_values(array_map(static fn ($b): string => \Agente\Text\Places::normalize((string) $b), $m)) : [];
    }

    /** Definición de una decisión en reglas/acciones.json (null si no existe). @return array<string,mixed>|null */
    public function actionFor(string $decision): ?array
    {
        $d = $this->actions['decisiones'][$decision] ?? null;

        return is_array($d) ? $d : null;
    }

    /** @return list<string> acciones de una decisión */
    public function actionsOf(string $decision): array
    {
        $a = $this->actionFor($decision)['acciones'] ?? [];

        return is_array($a) ? array_values(array_map('strval', $a)) : [];
    }
}
