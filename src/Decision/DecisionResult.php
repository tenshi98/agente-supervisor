<?php

declare(strict_types=1);

namespace Agente\Decision;

final class DecisionResult
{
    public const DECIDED = 'decidido';
    public const PENDING = 'pendiente';
    public const IRRELEVANT = 'irrelevante';

    /** @param list<ItemResult> $items */
    public function __construct(
        public readonly string $status,
        public readonly array $items,
        public readonly string $reason = '',
    ) {
    }

    /** Decisiones del correo para reglas/acciones.json. @return list<string> */
    public function decisions(): array
    {
        if ($this->status === self::IRRELEVANT && $this->items === []) {
            return ['descartar'];
        }
        $all = [];
        foreach ($this->items as $i) {
            foreach ($i->decisions() as $d) {
                $all[] = $d;
            }
        }

        return array_values(array_unique($all));
    }

    /** @return list<Doubt> */
    public function doubts(): array
    {
        $all = [];
        foreach ($this->items as $i) {
            foreach ($i->doubts as $d) {
                $all[] = $d;
            }
        }

        return $all;
    }
}
