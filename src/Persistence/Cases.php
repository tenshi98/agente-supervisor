<?php

declare(strict_types=1);

namespace Agente\Persistence;

use Agente\Decision\ItemResult;
use PDO;

final class Cases
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** Crea o actualiza el caso de una carga y devuelve su id. @param array<string,mixed> $message */
    public function upsert(int $tenantId, array $message, ItemResult $item): int
    {
        $d = $item->data;
        $decided = $item->state() === ItemResult::PENDING ? null : gmdate('Y-m-d H:i:s');
        $this->db->prepare(
            'INSERT INTO ndb_agent_cases (tenant_id, message_id, item_key, received, container, booking, shipment_ref, flow, data_json, state, decided, updated)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE container = VALUES(container), booking = VALUES(booking), shipment_ref = VALUES(shipment_ref), flow = VALUES(flow),
               data_json = VALUES(data_json), cross_done = IF(state = \'cruzado\' AND VALUES(state) = \'decidido\', cross_done, 0),
               state = IF(state = \'cruzado\' AND VALUES(state) = \'decidido\', state, VALUES(state)), decided = VALUES(decided), updated = UTC_TIMESTAMP()'
        )->execute([
            $tenantId, $message['message_id'], mb_substr($item->key, 0, 80), $message['received'],
            ($d['contenedor'] ?? '') ?: null, ($d['booking'] ?? '') ?: null, ($d['despacho'] ?? '') ?: null, ($d['flujo'] ?? '') ?: null,
            json_encode($d, JSON_UNESCAPED_UNICODE), $item->state(), $decided,
        ]);
        $st = $this->db->prepare('SELECT case_id FROM ndb_agent_cases WHERE message_id = ? AND item_key = ?');
        $st->execute([$message['message_id'], mb_substr($item->key, 0, 80)]);

        return (int) $st->fetchColumn();
    }

    /**
     * Cargas decididas listas para cruzar con el sistema externo: pasada la gracia y dentro de la ventana.
     *
     * @return list<array<string,mixed>>
     */
    public function dueForCross(int $tenantId, int $graceMin, int $windowHours, int $limit = 500): array
    {
        $st = $this->db->prepare(
            "SELECT * FROM ndb_agent_cases
              WHERE tenant_id = ? AND state IN ('decidido','cruzado') AND cross_done = 0
                AND received <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . max(0, $graceMin) . " MINUTE)
                AND received >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . max(1, $windowHours) . ' HOUR)
              ORDER BY received, case_id LIMIT ' . max(1, $limit)
        );
        $st->execute([$tenantId]);

        return $st->fetchAll();
    }

    /** Guarda el resultado de un cruce. $done = 1 cuando ya no hay que volver a cruzar esta carga. @param array<string,mixed> $detail */
    public function saveCross(int $caseId, string $crossState, array $detail, ?string $orderId, bool $done): void
    {
        $this->db->prepare(
            "UPDATE ndb_agent_cases SET cross_state = ?, cross_json = ?, order_id = COALESCE(?, order_id), cross_checked = UTC_TIMESTAMP(),
               cross_done = ?, state = IF(state = 'decidido', 'cruzado', state), updated = UTC_TIMESTAMP() WHERE case_id = ?"
        )->execute([mb_substr($crossState, 0, 20), json_encode($detail, JSON_UNESCAPED_UNICODE), $orderId, $done ? 1 : 0, $caseId]);
    }

    /** Las cargas que superaron la ventana sin resolverse dejan de cruzarse. */
    public function closeExpired(int $tenantId, int $windowHours): int
    {
        $st = $this->db->prepare(
            "UPDATE ndb_agent_cases SET cross_done = 1 WHERE tenant_id = ? AND cross_done = 0 AND state IN ('decidido','cruzado','ignorado')
               AND received < DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . max(1, $windowHours) . ' HOUR)'
        );
        $st->execute([$tenantId]);

        return $st->rowCount();
    }

    /** @return array<string,mixed>|null */
    public function get(int $caseId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM ndb_agent_cases WHERE case_id = ?');
        $st->execute([$caseId]);
        $r = $st->fetch();

        return $r === false ? null : $r;
    }
}
