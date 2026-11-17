<?php

declare(strict_types=1);

namespace Agente\Understanding;

use RuntimeException;

/**
 * Lo que la IA entendió de un correo, ya normalizado y validado en forma (no en contenido).
 * Siempre se guarda en ndb_agent_messages.extract_json para no volver a pagar la lectura.
 */
final class Extraction
{
    public const ROLES = ['inicio', 'cliente', 'final'];
    public const FLOWS = ['impo', 'expo', 'otro', 'desconocido'];
    /** Nombres antiguos de los puntos (extracciones ya guardadas): se leen como los oficiales. */
    private const LEGACY_ROLES = ['inicio' => 'origen', 'final' => 'destino'];
    public const DATES = ['retiro', 'entrega', 'devolucion', 'stacking'];

    /**
     * @param list<array<string,mixed>> $cargas
     * @param list<array{clave:string,valor:string,fuente:string}> $extras
     * @param list<string> $needOriginal
     */
    public function __construct(
        public readonly string $tipo,
        public readonly bool $relevante,
        public readonly string $confianza,
        public readonly bool $legible,
        public readonly array $needOriginal,
        public readonly array $cargas,
        public readonly array $extras,
        public readonly string $notas,
        public readonly string $flujo = 'desconocido',
    ) {
    }

    /** @throws RuntimeException si el texto no contiene un objeto JSON */
    public static function fromJson(string $text): self
    {
        $json = trim($text);
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $json, $m) === 1) {
            $json = trim($m[1]);
        }
        $a = strpos($json, '{');
        $b = strrpos($json, '}');
        if ($a === false || $b === false || $b < $a) {
            throw new RuntimeException('La IA no devolvió un objeto JSON');
        }
        $data = json_decode(substr($json, $a, $b - $a + 1), true);
        if (!is_array($data)) {
            throw new RuntimeException('JSON de la IA inválido: ' . json_last_error_msg());
        }

        return self::fromArray($data);
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d): self
    {
        $cargas = [];
        foreach ((is_array($d['cargas'] ?? null) ? $d['cargas'] : []) as $c) {
            if (is_array($c)) {
                $cargas[] = self::carga($c);
            }
        }
        $extras = [];
        foreach ((is_array($d['datos_adicionales'] ?? null) ? $d['datos_adicionales'] : []) as $x) {
            if (is_array($x) && trim((string) ($x['clave'] ?? '')) !== '') {
                $extras[] = ['clave' => trim((string) $x['clave']), 'valor' => trim((string) ($x['valor'] ?? '')), 'fuente' => trim((string) ($x['fuente'] ?? ''))];
            }
        }
        $need = [];
        foreach ((is_array($d['necesita_original'] ?? null) ? $d['necesita_original'] : []) as $n) {
            if (trim((string) $n) !== '') {
                $need[] = trim((string) $n);
            }
        }

        $flujo = mb_strtolower(trim((string) ($d['flujo'] ?? '')));
        if (!in_array($flujo, self::FLOWS, true)) {
            $flujo = 'desconocido';
        }

        return new self(
            mb_strtolower(trim((string) ($d['tipo_solicitud'] ?? 'otro'))) ?: 'otro',
            filter_var($d['relevante'] ?? false, FILTER_VALIDATE_BOOLEAN),
            Confidence::normalize($d['confianza'] ?? ''),
            filter_var($d['legible'] ?? true, FILTER_VALIDATE_BOOLEAN),
            $need,
            $cargas,
            $extras,
            trim((string) ($d['notas'] ?? '')),
            $flujo,
        );
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private static function carga(array $c): array
    {
        $lugares = [];
        foreach (self::ROLES as $role) {
            $lugares[$role] = [];
            $raw = $c['lugares'][$role] ?? (isset(self::LEGACY_ROLES[$role]) ? ($c['lugares'][self::LEGACY_ROLES[$role]] ?? null) : null);
            foreach ((is_array($raw) ? $raw : []) as $m) {
                if (is_string($m)) {
                    $m = ['texto' => $m];
                }
                if (is_array($m) && trim((string) ($m['texto'] ?? '')) !== '') {
                    $lugares[$role][] = [
                        'texto' => trim((string) $m['texto']),
                        'contexto' => trim((string) ($m['contexto'] ?? '')),
                        'fuente' => trim((string) ($m['fuente'] ?? '')),
                    ];
                }
            }
        }
        $fechas = [];
        foreach (self::DATES as $k) {
            $fechas[$k] = trim((string) ($c['fechas'][$k] ?? ''));
        }

        return [
            'contenedor' => strtoupper((string) preg_replace('/[\s\-]+/', '', (string) ($c['contenedor'] ?? ''))),
            'booking' => strtoupper(trim((string) ($c['booking'] ?? ''))),
            'despacho' => trim((string) ($c['despacho'] ?? '')),
            'lugares' => $lugares,
            'fechas' => $fechas,
            'confianza' => Confidence::normalize($c['confianza'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'tipo_solicitud' => $this->tipo,
            'flujo' => $this->flujo,
            'relevante' => $this->relevante,
            'confianza' => $this->confianza,
            'legible' => $this->legible,
            'necesita_original' => $this->needOriginal,
            'cargas' => $this->cargas,
            'datos_adicionales' => $this->extras,
            'notas' => $this->notas,
        ];
    }
}
