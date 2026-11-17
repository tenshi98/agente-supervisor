<?php

declare(strict_types=1);

namespace Agente\Persistence;

use Agente\Decision\Doubt;
use PDO;

final class Questions
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** Dudas abiertas con el mismo canon se reutilizan: un mismo dato se pregunta una sola vez. */
    public function openOrCreate(int $tenantId, Doubt $d, int $messageId): int
    {
        $st = $this->db->prepare("SELECT question_id FROM ndb_agent_questions WHERE tenant_id = ? AND canon = ? AND status IN ('nueva','enviada') LIMIT 1");
        $st->execute([$tenantId, mb_substr($d->canon, 0, 255)]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $this->db->prepare(
            "INSERT INTO ndb_agent_questions (tenant_id, token, kind, level, field, flow, canon, text, context, source, message_id, candidates_json, proposal_place_id, proposal_conf, status, created)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'nueva', UTC_TIMESTAMP())"
        )->execute([
            $tenantId, bin2hex(random_bytes(3)), $d->kind, $d->level, mb_substr($d->field, 0, 40), mb_substr($d->flow, 0, 12) ?: null, mb_substr($d->canon, 0, 255),
            mb_substr($d->text, 0, 255), mb_substr($d->context, 0, 500), mb_substr($d->source, 0, 120), $messageId,
            json_encode($d->candidates, JSON_UNESCAPED_UNICODE), $d->proposalPlaceId, $d->proposalConf,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function link(int $questionId, int $caseId): void
    {
        $this->db->prepare('INSERT IGNORE INTO ndb_agent_question_cases (question_id, case_id) VALUES (?, ?)')->execute([$questionId, $caseId]);
    }

    /**
     * Respuestas ya dadas que afectan a un correo: dudas propias (canon m<id>|...) y dudas de lugar (lugar|<texto>)
     * ligadas a alguna de sus cargas. Una respuesta de lugar sin «recordar» vale solo para esos correos.
     *
     * @return array<string,array{tipo:string,valor?:string,place_id?:string,recordar?:bool}>
     */
    public function answersForMessage(int $tenantId, int $messageId): array
    {
        $st = $this->db->prepare(
            "SELECT q.canon, q.answer_json FROM ndb_agent_questions q
              WHERE q.tenant_id = ? AND q.status = 'respondida'
                AND (q.canon LIKE ? OR (q.canon LIKE 'lugar|%' AND q.question_id IN (
                      SELECT qc.question_id FROM ndb_agent_question_cases qc JOIN ndb_agent_cases c ON c.case_id = qc.case_id WHERE c.message_id = ?)))"
        );
        $st->execute([$tenantId, 'm' . $messageId . '|%', $messageId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $a = json_decode((string) $r['answer_json'], true);
            if (is_array($a) && isset($a['tipo'])) {
                $out[(string) $r['canon']] = $a;
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function toSend(int $tenantId, int $limit): array
    {
        $st = $this->db->prepare("SELECT * FROM ndb_agent_questions WHERE tenant_id = ? AND status = 'nueva' ORDER BY question_id LIMIT " . max(0, $limit));
        $st->execute([$tenantId]);

        return $st->fetchAll();
    }

    public function sentToday(int $tenantId): int
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM ndb_agent_questions WHERE tenant_id = ? AND sent >= UTC_DATE()');
        $st->execute([$tenantId]);

        return (int) $st->fetchColumn();
    }

    public function markSent(int $questionId): void
    {
        $this->db->prepare("UPDATE ndb_agent_questions SET status = 'enviada', sent = UTC_TIMESTAMP() WHERE question_id = ? AND status = 'nueva'")->execute([$questionId]);
    }

    /** Dudas enviadas cuyo enlace ya venció sin respuesta vuelven a 'nueva' para reenviarse con un enlace fresco. */
    public function reopenExpired(int $tenantId, int $ttlHours): int
    {
        $st = $this->db->prepare("UPDATE ndb_agent_questions SET status = 'nueva' WHERE tenant_id = ? AND status = 'enviada' AND link_used = 0 AND sent < DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . max(1, $ttlHours) . ' HOUR)');
        $st->execute([$tenantId]);

        return $st->rowCount();
    }

    /** Busca una duda por el código corto del asunto («[Duda D-0012·ab12cd]»): lo usa la respuesta por correo. @return array<string,mixed>|null */
    public function byToken(int $tenantId, int $questionId, string $token): ?array
    {
        $st = $this->db->prepare('SELECT * FROM ndb_agent_questions WHERE tenant_id = ? AND question_id = ? AND token = ?');
        $st->execute([$tenantId, $questionId, $token]);
        $r = $st->fetch();

        return $r === false ? null : $r;
    }

    /** @return array<string,mixed>|null */
    public function get(int $questionId, int $tenantId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM ndb_agent_questions WHERE question_id = ? AND tenant_id = ?');
        $st->execute([$questionId, $tenantId]);
        $r = $st->fetch();

        return $r === false ? null : $r;
    }

    /** @return list<int> ids de los correos afectados por una duda */
    public function messageIds(int $questionId): array
    {
        $st = $this->db->prepare('SELECT DISTINCT c.message_id FROM ndb_agent_question_cases qc JOIN ndb_agent_cases c ON c.case_id = qc.case_id WHERE qc.question_id = ?');
        $st->execute([$questionId]);

        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Datos del correo que originó la duda (para mostrarlos al usuario). @return array<string,mixed>|null */
    public function sourceMessage(?int $messageId): ?array
    {
        if ($messageId === null) {
            return null;
        }
        $st = $this->db->prepare('SELECT subject, sender, received FROM ndb_agent_messages WHERE message_id = ?');
        $st->execute([$messageId]);
        $r = $st->fetch();

        return $r === false ? null : $r;
    }

    /**
     * Registra la respuesta de forma atómica. Devuelve false si el enlace ya se usó o la duda ya no está abierta
     * (así dos usuarios no pueden responder la misma duda ni un enlace se reutiliza).
     *
     * @param array<string,mixed> $answer
     */
    public function answer(int $questionId, int $recipientId, array $answer, string $via = 'enlace'): bool
    {
        $st = $this->db->prepare(
            "UPDATE ndb_agent_questions SET status = 'respondida', answered = UTC_TIMESTAMP(), answered_by = ?, answered_via = ?,
               answer_json = ?, link_used = 1, link_used_at = UTC_TIMESTAMP()
             WHERE question_id = ? AND link_used = 0 AND status IN ('nueva','enviada')"
        );
        $st->execute([$recipientId, $via, json_encode($answer, JSON_UNESCAPED_UNICODE), $questionId]);

        return $st->rowCount() === 1;
    }

    /** @return array{abiertas:int,respondidas:int} */
    public function counts(int $tenantId): array
    {
        $st = $this->db->prepare("SELECT SUM(status IN ('nueva','enviada')), SUM(status = 'respondida') FROM ndb_agent_questions WHERE tenant_id = ?");
        $st->execute([$tenantId]);
        $r = $st->fetch(PDO::FETCH_NUM);

        return ['abiertas' => (int) ($r[0] ?? 0), 'respondidas' => (int) ($r[1] ?? 0)];
    }
}
