<?php

declare(strict_types=1);

namespace Agente\Resolve;

/**
 * Elige, entre los candidatos del catálogo, el lugar que quiso decir el correo. NUNCA inventa: elige uno o «ninguno».
 * Una sola llamada por lote (se envían todos los textos juntos y se devuelve un resultado por cada ref).
 */
interface PlaceChooser
{
    /**
     * @param list<array{ref:string,texto:string,contexto:string,rol:string,flujo:string,candidatos:list<array{place_id:string,nombre:string,direccion:string,similitud:int}>}> $items
     * @return array<string,array{place_id:?string,confianza:string}> por ref; place_id null = ninguno
     */
    public function choose(int $tenantId, array $items): array;
}
