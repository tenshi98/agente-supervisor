<?php

declare(strict_types=1);

/**
 * Ingesta y comprensión (usa IA). Frecuencia sugerida: cada 5 a 15 minutos.
 *   php /ruta/www/agente/crons/agent_ingest.php
 *
 * Por tenant activo: lee las casillas (solo lectura) -> aplica las respuestas recibidas por correo -> entiende los correos
 * (prefiltro sin IA, IA con texto y solo si hace falta con el original) -> resuelve TODOS los lugares juntos
 * (una llamada MCP al catálogo, una a la IA) -> decide -> envía las dudas nuevas (tope por corrida y por día).
 * No cruza con el sistema externo ni envía avisos: eso lo hacen agent_check y agent_notify, sin IA.
 */

use Agente\Attachments\AttachmentReader;
use Agente\Decision\DecisionEngine;
use Agente\Mail\Ingestor;
use Agente\Mail\MailSourceFactory;
use Agente\Notify\QuestionNotifier;
use Agente\Persistence\Actions;
use Agente\Persistence\Aliases;
use Agente\Persistence\Cases;
use Agente\Persistence\Messages;
use Agente\Persistence\Outbox;
use Agente\Persistence\Questions;
use Agente\Persistence\Recipients;
use Agente\Persistence\Usage;
use Agente\Pipeline\AnswerService;
use Agente\Pipeline\Processor;
use Agente\Resolve\PlaceResolver;
use Agente\Support\CronRunner;
use Agente\Support\Services;
use Agente\Support\Vault;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(CronRunner::run(dirname(__DIR__), 'agent_ingest', static function (Services $svc, array $t): array {
    $tid = (int) $t['tenant_id'];
    $db = $svc->db;
    $stats = [];

    $ingestor = new Ingestor($db, $svc->config, $svc->log, new MailSourceFactory($svc->config, new Vault($svc->config->require('AGENT_VAULT_KEY'))), new AttachmentReader($svc->config, $svc->log));
    $stats['ingesta'] = $ingestor->run($tid);

    $messages = new Messages($db);
    $questions = new Questions($db);
    $aliases = new Aliases($db);
    $usage = new Usage($db);
    $actions = new Actions($db);
    $recipients = new Recipients($db);
    $resolver = new PlaceResolver($svc->rules, $svc->catalog(), $svc->chooser($t, static fn ($r) => $usage->record($tid, 'resolve', $r, null)));
    $processor = new Processor(
        $db, $messages, new Cases($db), $questions, $aliases, $usage, new DecisionEngine($svc->rules), $resolver, $svc->rules, $svc->log,
        $svc->extractor($t), $actions, new AnswerService($db, $questions, $aliases, $messages, $svc->rules, $actions), $recipients,
    );
    $stats['proceso'] = $processor->run($tid, $t, $svc->config->int('PROCESS_BATCH', 20));

    $notifier = new QuestionNotifier(
        $questions, $recipients, new Outbox($db), $svc->mailer($t), $svc->signer(), $svc->log, $svc->config->require('AGENT_BASE_URL'),
        $svc->templates($t), $svc->replyTo(), $svc->rules,
    );
    $stats['dudas'] = $notifier->sendPending($tid, (int) $t['max_questions_run'], (int) $t['max_questions_day'], (int) $t['link_ttl_hours']);

    return $stats;
}));
