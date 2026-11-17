<?php

declare(strict_types=1);

namespace Agente\Notify;

use Agente\Decision\Doubt;

/**
 * Redacta una duda con el vocabulario de operaciones (según el flujo). Lo usan el correo y la página de respuesta.
 * Vocabulario oficial: reglas/00_nomenclatura.md §1.
 */
final class QuestionText
{
    /** Nombre operativo de cada punto según el flujo. */
    private const POINTS = [
        'impo' => ['inicio' => 'Retiro en puerto', 'cliente' => 'Entrega al cliente', 'final' => 'Devolución del vacío'],
        'expo' => ['inicio' => 'Retiro del vacío', 'cliente' => 'Carga en planta del cliente', 'final' => 'Entrega en puerto'],
        'otro' => ['inicio' => 'Retiro', 'cliente' => 'Cliente', 'final' => 'Destino final'],
    ];

    private const LABELS = [
        'identificador' => 'contenedor, booking o despacho',
        'contenedor' => 'número de contenedor',
        'fecha_retiro' => 'fecha de retiro',
        'fecha_entrega' => 'fecha de entrega',
        'fecha_devolucion' => 'fecha de devolución',
        'fecha_stacking' => 'fecha de stacking',
        'carga' => 'carga',
        'tipo' => 'tipo de correo',
    ];

    /** Punto con vocabulario de operaciones; un campo que no es un punto devuelve su etiqueta general. */
    public static function label(string $field, string $flow = ''): string
    {
        if (in_array($field, ['inicio', 'cliente', 'final'], true)) {
            $f = isset(self::POINTS[$flow]) ? $flow : 'otro';

            return self::POINTS[$f][$field];
        }

        return self::LABELS[$field] ?? $field;
    }

    /**
     * @param array<string,mixed> $q fila de ndb_agent_questions
     * @return array{title:string,lead:string}
     */
    public static function describe(array $q): array
    {
        $field = (string) ($q['field'] ?? '');
        $label = self::label($field, (string) ($q['flow'] ?? ''));
        $text = (string) ($q['text'] ?? '');

        return match ((string) $q['kind']) {
            Doubt::KIND_PLACE => (string) ($q['level'] ?? '') === Doubt::LEVEL_CONFIRM
                ? ['title' => "¿{$label}: “{$text}” es el lugar propuesto?", 'lead' => "El correo indica “{$text}” como {$label}. Lo busqué en los lugares del sistema y propongo uno; confirma con un clic si es correcto."]
                : ['title' => "¿Qué lugar es “{$text}”?", 'lead' => "El correo menciona “{$text}” como {$label}, pero no estoy seguro de cuál es el lugar. Elige una opción o escríbelo."],
            Doubt::KIND_CONFLICT => [
                'title' => "“{$text}”: ¿cuál es el lugar correcto?",
                'lead' => "Para “{$text}” (usado como {$label}) hay dos respuestas distintas: una decisión que se tomó antes y una regla actual. Elige cuál vale.",
            ],
            Doubt::KIND_MISSING => [
                'title' => "Falta un dato: {$label}",
                'lead' => "El correo no trae el dato obligatorio “{$label}”. Indícalo si lo conoces, o ignora este punto.",
            ],
            Doubt::KIND_DATA => [
                'title' => "Revisar dato: {$label}",
                'lead' => "Leí “{$text}” como {$label}, pero el dato no es válido. " . (string) ($q['context'] ?? ''),
            ],
            default => $field === 'tipo'
                ? ['title' => '¿Es una instrucción de transporte?', 'lead' => "Leí este correo como “{$text}”, pero no estoy seguro. Confirma si es una instrucción de transporte que debo registrar."]
                : ['title' => "¿Interpreté bien la carga {$text}?", 'lead' => "Leí los datos de la carga {$text} con poca seguridad. Confirma que están bien o corrígelos."],
        };
    }

    /** Textos de los botones por tipo de duda. @return array{confirm:?string,ignore:string,value:?string,remember:bool} */
    public static function actions(string $kind, string $field, string $level = ''): array
    {
        return match ($kind) {
            Doubt::KIND_PLACE => [
                'confirm' => $level === Doubt::LEVEL_CONFIRM ? 'Es este' : null,
                'ignore' => 'No es un lugar / ignorar', 'value' => 'Otro lugar (escribe el nombre)', 'remember' => true,
            ],
            Doubt::KIND_CONFLICT => ['confirm' => null, 'ignore' => 'No es un lugar / ignorar', 'value' => 'Otro (escribe el nombre)', 'remember' => true],
            Doubt::KIND_MISSING => ['confirm' => null, 'ignore' => 'Ignorar este punto', 'value' => 'Escribe el dato', 'remember' => false],
            Doubt::KIND_DATA => ['confirm' => null, 'ignore' => 'Ignorar este dato', 'value' => 'Escribe el valor correcto', 'remember' => false],
            default => $field === 'tipo'
                ? ['confirm' => 'Sí, es una instrucción de transporte', 'ignore' => 'No, ignorar el correo', 'value' => null, 'remember' => false]
                : ['confirm' => 'Sí, está bien', 'ignore' => 'No, ignorar esta carga', 'value' => 'Corregir (escribe la corrección)', 'remember' => false],
        };
    }
}
