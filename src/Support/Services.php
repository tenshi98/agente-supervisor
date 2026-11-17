<?php

declare(strict_types=1);

namespace Agente\Support;

use Agente\Llm\AnthropicClient;
use Agente\Mcp\CatalogClient;
use Agente\Mcp\FixtureMcp;
use Agente\Mcp\HttpMcpClient;
use Agente\Mcp\McpClient;
use Agente\Mcp\McpLog;
use Agente\Mcp\OrdersClient;
use Agente\Notify\FileMailer;
use Agente\Notify\Mailer;
use Agente\Notify\SmtpMailer;
use Agente\Notify\Templates;
use Agente\Resolve\LlmPlaceChooser;
use Agente\Resolve\PlaceChooser;
use Agente\Rules\RuleSet;
use Agente\Understanding\Extractor;
use Agente\Understanding\OriginalLoader;
use PDO;

/**
 * Arma, una sola vez por proceso, las piezas que comparten todos los crons (configuración, BD, reglas, MCP, correo, IA).
 * Los crons solo se ocupan de qué hacer; aquí se decide con qué.
 */
final class Services
{
    public readonly Config $config;
    public readonly PDO $db;
    public readonly RuleSet $rules;
    public readonly Logger $log;
    private ?McpClient $mcp = null;

    public function __construct(public readonly string $base, bool $echoLog = false, string $logName = 'agent.log')
    {
        $this->config = Config::load($base);
        $this->log = new Logger($base . '/logs/' . $logName, $echoLog);
        $this->db = Db::agent($this->config);
        $this->rules = RuleSet::load($this->config->get('RULES_DIR') ?? $base . '/reglas');
    }

    /**
     * Tenants activos con su configuración (una fila por tenant: columnas de ndb_agent_tenants y ndb_agent_config).
     *
     * @return list<array<string,mixed>>
     */
    public function tenants(): array
    {
        return $this->db->query(
            'SELECT t.name, t.timezone, c.*
               FROM ndb_agent_tenants t JOIN ndb_agent_config c ON c.tenant_id = t.tenant_id
              WHERE t.active = 1 AND c.active = 1 ORDER BY t.tenant_id'
        )->fetchAll();
    }

    /** @param array<string,mixed> $tenant */
    public function dryRun(array $tenant): bool
    {
        return (int) $tenant['dry_run'] === 1 || $this->config->bool('DRY_RUN');
    }

    /** MCP_URL=fake usa storage/fake_mcp.json (demo y pruebas manuales). */
    public function mcp(): McpClient
    {
        if ($this->mcp !== null) {
            return $this->mcp;
        }
        $url = (string) ($this->config->get('MCP_URL') ?? '');
        if ($url === 'fake') {
            return $this->mcp = FixtureMcp::fromFile($this->base . '/storage/demo_mcp.json');
        }
        if ($url === '') {
            throw new \RuntimeException('Falta MCP_URL: sin el sistema externo no se pueden resolver lugares ni cruzar.');
        }

        return $this->mcp = new HttpMcpClient(
            $url, $this->config->require('MCP_TOKEN'), $this->config->int('MCP_BATCH_MAX', 50), $this->config->int('MCP_TIMEOUT', 30),
            new McpLog($this->db),
        );
    }

    public function catalog(): CatalogClient
    {
        return new CatalogClient($this->mcp());
    }

    public function orders(): OrdersClient
    {
        return new OrdersClient($this->mcp());
    }

    public function signer(): LinkSigner
    {
        return new LinkSigner($this->config->require('AGENT_LINK_SECRET'));
    }

    /** Casilla que lee las respuestas por correo (la de los mailto:). */
    public function replyTo(): string
    {
        return (string) ($this->config->get('MAIL_REPLY_TO') ?? $this->config->get('MAIL_FROM') ?? '');
    }

    /** @param array<string,mixed> $tenant */
    public function templates(array $tenant): Templates
    {
        return new Templates($this->base . '/plantillas', [
            'brand_name' => (string) ($tenant['brand_name'] ?? ''),
            'brand_footer' => (string) ($tenant['brand_footer'] ?? ''),
            'dry_run' => $this->dryRun($tenant),
            'base_url' => (string) ($this->config->get('AGENT_BASE_URL') ?? ''),
        ]);
    }

    /** SMTP real solo si no es dry_run y hay SMTP_HOST; si no, vistas previas en storage/outbox/. @param array<string,mixed> $tenant */
    public function mailer(array $tenant): Mailer
    {
        $host = $this->config->get('SMTP_HOST');
        if ($this->dryRun($tenant) || $host === null) {
            return new FileMailer($this->base . '/storage/outbox');
        }

        return new SmtpMailer(
            $host, $this->config->int('SMTP_PORT', 587), strtolower($this->config->get('SMTP_SECURITY', 'starttls') ?? 'starttls'),
            (string) $this->config->get('SMTP_USER', ''), (string) $this->config->get('SMTP_PASS', ''),
            $this->config->require('MAIL_FROM'), (string) $this->config->get('MAIL_FROM_NAME', 'Agente Supervisor'),
        );
    }

    /** @param array<string,mixed> $tenant */
    public function llm(array $tenant): ?AnthropicClient
    {
        $key = $this->config->get('ANTHROPIC_API_KEY');
        if ($key === null) {
            return null;
        }

        return new AnthropicClient(
            $key, (string) $tenant['llm_model'],
            (float) ($this->config->get('AI_PRICE_IN_PER_MTOK', '2') ?? 2), (float) ($this->config->get('AI_PRICE_OUT_PER_MTOK', '10') ?? 10),
            $this->config->int('AI_TIMEOUT', 120),
        );
    }

    /** @param array<string,mixed> $tenant */
    public function extractor(array $tenant): ?Extractor
    {
        $llm = $this->llm($tenant);

        return $llm === null ? null : new Extractor($llm, $this->rules, new OriginalLoader($this->config, $this->log), $this->log);
    }

    /** Elige entre candidatos del catálogo con IA (una llamada por lote). @param callable(\Agente\Llm\LlmResponse):void $onUsage */
    public function chooser(array $tenant, callable $onUsage): ?PlaceChooser
    {
        $llm = $this->llm($tenant);

        return $llm === null ? null : new LlmPlaceChooser($llm, $onUsage);
    }
}
