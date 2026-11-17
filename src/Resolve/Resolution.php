<?php

declare(strict_types=1);

namespace Agente\Resolve;

use Agente\Text\Places;

/** Cómo quedó resuelto el texto de un lugar. Vocabulario: reglas/00_nomenclatura.md §6 y §7. */
final class Resolution
{
    public const AUTOMATIC = 'automatica';
    public const CONFIRM = 'confirmacion';
    public const OPEN = 'abierta';
    /** El sistema externo no respondió: no se decide ni se pregunta, el correo se reintenta en la próxima corrida. */
    public const DEFER = 'diferida';

    /**
     * @param string $level automatica | confirmacion | abierta | diferida
     * @param string $method equivalencia | generico | exacto | parecido | ia | marca | conflicto | ninguno
     * @param list<array{place_id:string,nombre:string,direccion:string,similitud:int}> $candidates
     * @param ?string $zone zona para verificar un genérico
     */
    public function __construct(
        public readonly string $level,
        public readonly string $method,
        public readonly ?string $placeId = null,
        public readonly ?string $name = null,
        public readonly array $candidates = [],
        public readonly ?string $proposalPlaceId = null,
        public readonly ?string $proposalConf = null,
        public readonly bool $ignored = false,
        public readonly bool $conflict = false,
        public readonly ?string $zone = null,
        public readonly ?string $brand = null,
        /** @var list<string> */
        public readonly array $conflictOptions = [],
    ) {
    }

    public function isResolved(): bool
    {
        return $this->level === self::AUTOMATIC;
    }

    /** Clave estable de un texto en un punto: «inicio|dpw». */
    public static function key(string $role, string $text): string
    {
        return $role . '|' . Places::normalize($text);
    }
}
