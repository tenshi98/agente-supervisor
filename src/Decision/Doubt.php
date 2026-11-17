<?php

declare(strict_types=1);

namespace Agente\Decision;

/**
 * Algo que el agente no puede decidir solo y debe consultar a un destinatario con rol «duda».
 * Vocabulario: reglas/00_nomenclatura.md (tipo de duda, nivel, campo).
 */
final class Doubt
{
    public const KIND_PLACE = 'lugar';
    public const KIND_CARGO = 'carga';
    public const KIND_MISSING = 'faltante';
    public const KIND_DATA = 'dato';
    public const KIND_CONFLICT = 'conflicto';

    /** Nivel de decisión «confirmacion»: se propone un lugar y basta un clic. «abierta»: hay que elegir o escribir. */
    public const LEVEL_CONFIRM = 'confirmacion';
    public const LEVEL_OPEN = 'abierta';

    /**
     * @param list<array{place_id:string,nombre:string,direccion:string,similitud:int}> $candidates opciones sugeridas
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $field,
        public readonly string $canon,
        public readonly string $text,
        public readonly string $context,
        public readonly string $source,
        public readonly array $candidates,
        public readonly string $itemKey,
        public readonly string $level = self::LEVEL_OPEN,
        public readonly ?string $proposalPlaceId = null,
        public readonly ?string $proposalConf = null,
        public readonly string $flow = '',
    ) {
    }
}
