<?php

declare(strict_types=1);

namespace Agente\Check;

use Agente\Mcp\OrdersClient;
use Agente\Persistence\Actions;
use Agente\Persistence\Aliases;
use Agente\Persistence\Alerts;
use Agente\Persistence\Cases;
use Agente\Rules\RuleSet;

/**
 * Cron `agent_check` (sin IA). Cruza las cargas decididas con el sistema externo pasada la gracia y dentro de la ventana.
 * Ahorro de consultas: UNA llamada buscar_ordenes con todas las cargas y UNA tramos_de_ordenes con todas las órdenes
 * encontradas (≤ 2 llamadas por corrida, sin importar cuántas cargas haya).
 */
final class Crosschecker
{
    public function __construct(
        private readonly Cases $cases,
        private readonly Alerts $alerts,
        private readonly Actions $actions,
        private readonly Aliases $aliases,
        private readonly OrdersClient $orders,
        private readonly RuleSet $rules,
    ) {
    }

    /**
     * @param array<string,mixed> $cfg fila de ndb_agent_config (grace_min, window_hours)
     * @return array<string,int>
     */
    public function run(int $tenantId, array $cfg, string $process = 'check'): array
    {
        $stats = ['cargas' => 0, 'sin_identificador' => 0, 'omitidas' => 0, 'ok' => 0, 'avisos' => 0, 'informativos' => 0, 'cerradas' => 0];
        $grace = (int) ($cfg['grace_min'] ?? $this->rules->get('cruce.gracia_min', 60));
        $window = (int) ($cfg['window_hours'] ?? $this->rules->get('cruce.ventana_horas', 72));
        $due = $this->cases->dueForCross($tenantId, $grace, $window);
        $stats['cargas'] = count($due);

        $items = [];
        $byRef = [];
        foreach ($due as $c) {
            $ref = 'c' . $c['case_id'];
            $container = (string) ($c['container'] ?? '');
            $booking = (string) ($c['booking'] ?? '');
            $despacho = (string) ($c['shipment_ref'] ?? '');
            if ($container === '' && $booking === '' && $despacho === '') {
                // Sin identificador no hay con qué buscar la orden: no se puede verificar.
                $this->cases->saveCross((int) $c['case_id'], 'no_verificable', [], null, true);
                $stats['sin_identificador']++;
                continue;
            }
            $byRef[$ref] = $c;
            $items[] = ['ref' => $ref, 'contenedor' => $container, 'despacho' => $despacho, 'booking' => $booking, 'fecha_correo' => substr((string) $c['received'], 0, 10)];
        }

        $found = $items === [] ? [] : $this->orders->find($tenantId, $items, $process);
        $legReq = [];
        foreach ($byRef as $ref => $c) {
            $o = $found[$ref] ?? null;
            if ($o !== null && $o['status'] === 'ok' && $o['orden'] !== null) {
                $legReq[] = ['ref' => $ref, 'service_id' => (string) ($o['orden']['service_id'] ?? ''), 'contenedor' => (string) ($c['container'] ?? '')];
            }
        }
        $legs = $legReq === [] ? [] : $this->orders->legs($tenantId, $legReq, $process);

        $needCfg = (array) $this->rules->get('cruce.cuando_hace_falta', []);
        $needCfg = array_filter($needCfg, 'is_string');
        $threshold = (int) $this->rules->get('equivalencias.contradicciones_para_revision', 3);

        foreach ($byRef as $ref => $c) {
            $data = json_decode((string) ($c['data_json'] ?? ''), true);
            $data = is_array($data) ? $data : [];
            $r = CaseEvaluator::evaluate($data, $found[$ref] ?? null, $legs[$ref] ?? null, $needCfg);
            if ($r['skip']) {
                $stats['omitidas']++; // el sistema no respondió: se reintenta en la próxima corrida
                continue;
            }
            $caseId = (int) $c['case_id'];
            foreach ($r['alerts'] as $a) {
                $this->raise($tenantId, $caseId, $a, (string) ($c['flow'] ?? ''), $r['order_id'], $stats);
            }
            foreach (array_unique($r['contradicted']) as $norm) {
                $this->aliases->contradicted($tenantId, $norm, $threshold);
            }
            if ($r['alerts'] === []) {
                $stats['ok']++;
            }
            $this->cases->saveCross($caseId, $r['state'], ['puntos' => $r['points']], $r['order_id'], $r['done']);
        }
        $stats['cerradas'] = $this->cases->closeExpired($tenantId, $window);

        return $stats;
    }

    /** @param array<string,mixed> $a @param array<string,int> $stats */
    private function raise(int $tenantId, int $caseId, array $a, string $flow, ?string $orderId, array &$stats): void
    {
        $decision = (string) $a['decision'];
        $acts = $this->rules->actionsOf($decision);
        $executed = (bool) $a['executed'];
        if (!in_array('crear_aviso', $acts, true) && !in_array('solo_resumen_diario', $acts, true)) {
            return;
        }
        $id = $this->alerts->create($tenantId, $caseId, [
            'kind' => (string) $a['kind'], 'point' => $a['point'], 'mail_value' => (string) $a['mail_value'], 'system_value' => (string) $a['system_value'],
            'source' => (string) $a['source'], 'executed' => $executed || in_array('solo_resumen_diario', $acts, true), 'flow' => $flow, 'order_id' => $orderId,
        ]);
        if ($id === null) {
            return;
        }
        foreach ($acts as $act) {
            $this->actions->once($tenantId, $decision, $act, 'c' . $caseId . '|' . ($a['point'] ?? 'carga'), ['alert_id' => $id]);
        }
        $executed ? $stats['informativos']++ : $stats['avisos']++;
    }
}
