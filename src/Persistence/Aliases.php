<?php

declare(strict_types=1);

namespace Agente\Persistence;

use PDO;

/**
 * Equivalencias: texto del correo (normalizado) -> lugar del catálogo. Lo que un destinatario decidió con «recordar»
 * (o una semilla) se consulta antes de volver a preguntar. Antes se llamaba «memoria».
 */
final class Aliases
{
    public const IGNORE = '__IGNORAR__';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,array{value:string,place_id:?string,source:string,review:bool}> texto normalizado => equivalencia vigente */
    public function places(int $tenantId): array
    {
        $st = $this->db->prepare("SELECT key_norm, value, place_id, source, needs_review FROM ndb_agent_aliases WHERE tenant_id = ? AND kind = 'lugar' AND scope = 'tenant' AND active = 1 ORDER BY alias_id");
        $st->execute([$tenantId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(string) $r['key_norm']] = [
                'value' => (string) $r['value'],
                'place_id' => $r['place_id'] !== null ? (string) $r['place_id'] : null,
                'source' => (string) $r['source'],
                'review' => (bool) $r['needs_review'],
            ];
        }

        return $out;
    }

    /**
     * Guarda una equivalencia. Si se cambia una anterior, la vieja queda inactiva y se cuenta la contradicción.
     */
    public function remember(int $tenantId, string $keyNorm, string $value, ?string $placeId, string $source, ?int $questionId, ?int $userId): void
    {
        $keyNorm = mb_substr($keyNorm, 0, 255);
        $value = mb_substr($value, 0, 255);
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $st = $this->db->prepare("SELECT alias_id, value, place_id, contradictions FROM ndb_agent_aliases WHERE tenant_id = ? AND kind = 'lugar' AND scope = 'tenant' AND key_norm = ? AND active = 1");
            $st->execute([$tenantId, $keyNorm]);
            $contradictions = 0;
            foreach ($st->fetchAll() as $p) {
                if ($p['value'] === $value && ($placeId === null || $p['place_id'] === $placeId)) {
                    $this->db->prepare('UPDATE ndb_agent_aliases SET source = ?, updated = UTC_TIMESTAMP() WHERE alias_id = ?')->execute([$source, $p['alias_id']]);
                    if ($own) {
                        $this->db->commit();
                    }

                    return;
                }
                $contradictions = max($contradictions, (int) $p['contradictions'] + 1);
                $this->db->prepare('UPDATE ndb_agent_aliases SET active = 0, updated = UTC_TIMESTAMP() WHERE alias_id = ?')->execute([$p['alias_id']]);
            }
            $this->db->prepare(
                "INSERT INTO ndb_agent_aliases (tenant_id, kind, scope, scope_value, key_norm, place_id, value, source, question_id, contradictions, created_by, created, updated)
                 VALUES (?, 'lugar', 'tenant', '', ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([$tenantId, $keyNorm, $placeId, $value, $source, $questionId, $contradictions, $userId]);
            if ($own) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @param list<string> $keyNorms */
    public function bumpUses(int $tenantId, array $keyNorms): void
    {
        $st = $this->db->prepare("UPDATE ndb_agent_aliases SET uses = uses + 1 WHERE tenant_id = ? AND kind = 'lugar' AND key_norm = ? AND active = 1");
        foreach (array_unique($keyNorms) as $k) {
            $st->execute([$tenantId, mb_substr($k, 0, 255)]);
        }
    }

    /**
     * El sistema externo contradijo una equivalencia: baja su salud y, al llegar al umbral de contradicciones,
     * la marca para revisión (no se desactiva sola: lo decide una persona).
     *
     * @param int $threshold contradicciones que piden revisión (reglas/decision.json: equivalencias.contradicciones_para_revision)
     */
    public function contradicted(int $tenantId, string $keyNorm, int $threshold): void
    {
        $this->db->prepare(
            "UPDATE ndb_agent_aliases
                SET contradictions = contradictions + 1,
                    health = GREATEST(0, health - 20),
                    needs_review = IF(contradictions + 1 >= ?, 1, needs_review),
                    updated = UTC_TIMESTAMP()
              WHERE tenant_id = ? AND kind = 'lugar' AND key_norm = ? AND active = 1"
        )->execute([max(1, $threshold), $tenantId, mb_substr($keyNorm, 0, 255)]);
    }

    /** Siembra equivalencias (texto normalizado => nombre) sin pisar las que ya existen. Devuelve cuántas creó. @param array<string,string> $map */
    public function seed(int $tenantId, array $map, string $source = 'semilla'): int
    {
        $exists = $this->db->prepare("SELECT 1 FROM ndb_agent_aliases WHERE tenant_id = ? AND kind = 'lugar' AND key_norm = ? AND active = 1 LIMIT 1");
        $ins = $this->db->prepare(
            "INSERT INTO ndb_agent_aliases (tenant_id, kind, scope, scope_value, key_norm, value, source, created, updated)
             VALUES (?, 'lugar', 'tenant', '', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $n = 0;
        foreach ($map as $key => $value) {
            $key = mb_substr((string) $key, 0, 255);
            $exists->execute([$tenantId, $key]);
            if ($exists->fetchColumn() !== false) {
                continue;
            }
            $ins->execute([$tenantId, $key, mb_substr((string) $value, 0, 255), $source]);
            $n++;
        }

        return $n;
    }

    /** @return list<array<string,mixed>> equivalencias pendientes de revisión */
    public function needingReview(int $tenantId): array
    {
        $st = $this->db->prepare('SELECT alias_id, key_norm, value, contradictions, health FROM ndb_agent_aliases WHERE tenant_id = ? AND active = 1 AND needs_review = 1 ORDER BY health, alias_id');
        $st->execute([$tenantId]);

        return $st->fetchAll();
    }
}
