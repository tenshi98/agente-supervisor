<?php

declare(strict_types=1);

namespace Agente\Decision;

/** Resultado de decidir una carga (o el correo completo si no trae cargas identificables). */
final class ItemResult
{
    public const DECIDED = 'decidido';
    public const PENDING = 'pendiente';
    public const IGNORED = 'ignorado';

    /** @param array<string,mixed> $data datos ya resueltos @param list<Doubt> $doubts */
    public function __construct(
        public readonly string $key,
        public readonly array $data,
        public readonly array $doubts,
        public readonly bool $ignored = false,
    ) {
    }

    /**
     * Nombres de decisión de esta carga (reglas/acciones.json): automatica, confirmacion, abierta, conflicto,
     * faltante, dato_invalido, carga_dudosa. Una carga sin dudas es «automatica».
     *
     * @return list<string>
     */
    public function decisions(): array
    {
        if ($this->ignored) {
            return [];
        }
        if ($this->doubts === []) {
            return ['automatica'];
        }
        $out = [];
        foreach ($this->doubts as $d) {
            $out[] = match ($d->kind) {
                Doubt::KIND_PLACE => $d->level === Doubt::LEVEL_CONFIRM ? 'confirmacion' : 'abierta',
                Doubt::KIND_CONFLICT => 'conflicto',
                Doubt::KIND_MISSING => 'faltante',
                Doubt::KIND_DATA => 'dato_invalido',
                default => 'carga_dudosa',
            };
        }

        return array_values(array_unique($out));
    }

    public function state(): string
    {
        if ($this->ignored) {
            return self::IGNORED;
        }

        return $this->doubts === [] ? self::DECIDED : self::PENDING;
    }
}
