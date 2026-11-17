# Configuración: variables de entorno (`config/.env`)

Copia de `config/.env.example`; las variables de entorno reales tienen prioridad. El archivo `.env` no se versiona. Las variables `MCP_*` (servidor del sistema externo) se describen además en [mcp.md](mcp.md).

| Variable | Defecto | Descripción |
|---|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME` | `localhost`, `3306`, `agente` | conexión a MySQL |
| `DB_USER_HOST` | `%` | host desde el que se crean los usuarios de BD |
| `DB_TIMEOUT`, `DB_RO_QUERY_TIMEOUT` | `10`, `30` | segundos de conexión y de consulta de solo lectura |
| `DB_AGENT_USER/PASS`, `DB_RO_USER/PASS`, `DB_ADMIN_USER/PASS` | — | usuarios de BD (el admin solo para `migrate.php`) |
| `AGENT_TENANT_NAME`, `AGENT_TIMEZONE` | `Agente`, `America/Santiago` | tenant que crea `migrate.php` (editable) |
| `AGENT_VAULT_KEY`, `AGENT_LINK_SECRET` | — | cifrado de credenciales y firma de enlaces (`make_keys.php`) |
| `AGENT_BASE_URL` | `http://localhost/agente/public` | URL pública de `public/` |
| `DRY_RUN` | `1` | fuerza modo de prueba en todos los tenants |
| `MCP_URL`, `MCP_TOKEN` | — | servidor MCP del sistema externo (`fake` = datos simulados) |
| `MCP_BATCH_MAX`, `MCP_TIMEOUT` | `50`, `30` | elementos máximos por llamada y tiempo de espera |
| `MAIL_REPLY_TO` | `MAIL_FROM` | casilla a la que responden los botones `mailto:`; debe ser una de las casillas leídas |
| `CRON_WEB_ENABLED`, `CRON_WEB_SECRET` | `0`, — | ejecutar crons por web con `?key=` (≥ 32 caracteres) |
| `CRON_MAX_SECONDS`, `PROCESS_BATCH`, `INGEST_BATCH` | `600`, `20`, `50` | tiempo máximo, correos por corrida y mensajes por casilla |
| `RULES_DIR` | `reglas/` | carpeta de reglas |
| `GOOGLE_CLIENT_ID/SECRET`, `GOOGLE_REDIRECT_URI` | — | OAuth de casillas Gmail |
| `MAIL_ALLOWED_SENDERS`, `MAIL_DISCARD_SENDERS` | vacío | remitentes permitidos y avisos a descartar |
| `OCR_ENABLED`, `OCR_LANGS`, `ATTACH_MAX_PDF_PAGES`, `ATTACH_MAX_IMAGES`, `ATTACH_MAX_BYTES`, `ATTACH_KEEP_DAYS` | `1`, `spa+eng`, `2`, `4`, `15000000`, `7` | lectura de adjuntos |
| `ANTHROPIC_API_KEY`, `AI_PRICE_IN_PER_MTOK`, `AI_PRICE_OUT_PER_MTOK`, `AI_TIMEOUT` | —, `2`, `10`, `120` | IA y control de gasto |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURITY`, `SMTP_USER`, `SMTP_PASS`, `MAIL_FROM`, `MAIL_FROM_NAME` | — | SMTP de salida (sin `SMTP_HOST` solo vistas previas) |
| `HTTP_TIMEOUT`, `HTTP_CONNECT_TIMEOUT` | `30`, `10` | tiempos de espera |

Llaves: `php tools/make_keys.php` genera `AGENT_VAULT_KEY` y `AGENT_LINK_SECRET`. Si cambias `AGENT_VAULT_KEY`, hay que volver a registrar las casillas.

---

[← Volver al README](../README.md)
