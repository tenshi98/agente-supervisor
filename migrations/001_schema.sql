-- Agente Supervisor: tablas propias (ndb_agent_*). MySQL 8.4 / InnoDB / utf8mb4.
-- Todas llevan tenant_id (multi-tenant desde el inicio). El agente escribe SOLO en estas tablas.
-- Las fechas se guardan en UTC; la hora del tenant se aplica en PHP (ndb_agent_tenants.timezone).
--
-- Flujo de estados de un correo (ndb_agent_messages.status):
--   nuevo      -> (reservado)
--   descartado -> no pasó el filtro previo de remitentes; no se lee más
--   listo      -> ingerido; espera comprensión/decisión (también vuelve a este estado al responderse una duda)
--   pendiente  -> hay dudas sin responder (ndb_agent_questions)
--   procesado  -> decidido por completo (o irrelevante)
--   error      -> falló 3 veces
-- Vocabulario oficial: reglas/00_nomenclatura.md (inicio/cliente/final, niveles de decisión, estados).
-- El agente no conoce ningún sistema externo en particular: todo lo externo llega por MCP (docs/mcp.md).

CREATE TABLE IF NOT EXISTS `ndb_agent_tenants` (
  `tenant_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`      VARCHAR(100) NOT NULL,
  `timezone`  VARCHAR(40) NOT NULL DEFAULT 'America/Santiago',
  `active`    TINYINT(1) NOT NULL DEFAULT 1,
  `created`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ndb_agent_config` (
  `config_id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`         INT UNSIGNED NOT NULL,
  `active`            TINYINT(1) NOT NULL DEFAULT 0,             -- 0 = el cron ignora al tenant
  `dry_run`           TINYINT(1) NOT NULL DEFAULT 1,             -- 1 = no envía correos (deja vistas previas en storage/outbox)
  `max_questions_run` SMALLINT NOT NULL DEFAULT 8,               -- dudas enviadas por corrida
  `max_questions_day` SMALLINT NOT NULL DEFAULT 30,              -- dudas enviadas por día
  `llm_model`         VARCHAR(60) NOT NULL DEFAULT 'claude-sonnet-5-5',
  `ai_max_usd_day`    DECIMAL(8,2) NOT NULL DEFAULT 5.00,        -- tope diario de gasto en IA
  `link_ttl_hours`    SMALLINT NOT NULL DEFAULT 72,              -- vigencia del enlace de una duda
  `grace_min`         SMALLINT NOT NULL DEFAULT 60,              -- minutos de gracia antes de cruzar con el sistema externo
  `window_hours`      SMALLINT NOT NULL DEFAULT 72,              -- el cruce se reintenta hasta esta antigüedad del correo
  `check_every_min`   SMALLINT NOT NULL DEFAULT 15,              -- frecuencia del cruce (informativo para el cron)
  `notify_every_min`  SMALLINT NOT NULL DEFAULT 60,              -- frecuencia del resumen de avisos
  `summary_hour`      TINYINT NOT NULL DEFAULT 19,               -- hora local del resumen diario
  `retention_days`    SMALLINT NOT NULL DEFAULT 90,              -- días que se guarda el contenido de los correos
  `brand_name`        VARCHAR(80) NOT NULL DEFAULT '',           -- nombre que muestran las plantillas (vacío = sin marca)
  `brand_footer`      VARCHAR(200) NOT NULL DEFAULT '',          -- pie de las plantillas
  `last_update`       DATETIME NULL,
  PRIMARY KEY (`config_id`),
  UNIQUE KEY `uq_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ndb_agent_recipients` (
  `recipient_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL,
  `email`        VARCHAR(150) NOT NULL,
  `name`         VARCHAR(120) NULL,
  `role`         VARCHAR(20) NOT NULL DEFAULT 'duda',            -- duda (decide, al momento) | aviso (resumen por hora) | resumen (diario)
  `user_id`      INT NULL,
  `active`       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`recipient_id`),
  UNIQUE KEY `uq_email_role` (`tenant_id`, `email`, `role`),
  KEY `idx_role` (`tenant_id`, `role`, `active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ndb_agent_mailboxes` (
  `mailbox_id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`        INT UNSIGNED NOT NULL,
  `name`             VARCHAR(100) NOT NULL,
  `email`            VARCHAR(150) NOT NULL,
  `source_type`      VARCHAR(10) NOT NULL DEFAULT 'gmail_api',    -- gmail_api | imap | folder (carpeta local, pruebas)
  `host`             VARCHAR(150) NULL,                           -- IMAP: servidor; folder: ruta de la carpeta
  `port`             SMALLINT NOT NULL DEFAULT 993,
  `security`         VARCHAR(10) NOT NULL DEFAULT 'ssl',          -- ssl | starttls
  `auth_type`        VARCHAR(20) NOT NULL DEFAULT 'oauth_google', -- oauth_google | password | app_password | none
  `username`         VARCHAR(150) NOT NULL,
  `secret_enc`       TEXT NULL,                                   -- JSON cifrado con sodium secretbox (refresh_token o password)
  `folders`          VARCHAR(255) NOT NULL DEFAULT 'INBOX',       -- carpetas IMAP o labels de Gmail, separados por coma
  `filter_from`      TEXT NULL,                                   -- JSON: {"allowed":[...],"discard":[...]}
  `last_ok`          DATETIME NULL,
  `last_error`       VARCHAR(500) NULL,
  `active`           TINYINT(1) NOT NULL DEFAULT 1,
  `created`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`mailbox_id`),
  KEY `idx_tenant` (`tenant_id`, `active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ndb_agent_checkpoints` (
  `checkpoint_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT UNSIGNED NOT NULL,
  `mailbox_id`    INT UNSIGNED NOT NULL,
  `folder`        VARCHAR(190) NOT NULL,
  `uid_validity`  BIGINT NULL,                                    -- IMAP
  `last_uid`      BIGINT NULL,                                    -- IMAP
  `last_ts_ms`    BIGINT NULL,                                    -- Gmail API / folder: marca de tiempo del último mensaje visto
  `updated`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`checkpoint_id`),
  UNIQUE KEY `uq_mailbox_folder` (`mailbox_id`, `folder`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ndb_agent_messages` (
  `message_id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`      INT UNSIGNED NOT NULL,
  `mailbox_id`     INT UNSIGNED NOT NULL,
  `rfc_message_id` VARCHAR(255) NOT NULL,                         -- Message-ID: deduplica
  `folder`         VARCHAR(190) NULL,
  `source_id`      VARCHAR(255) NULL,                             -- id en el origen (Gmail id o UID IMAP)
  `received`       DATETIME NOT NULL,
  `sender`         VARCHAR(255) NULL,
  `subject`        VARCHAR(255) NULL,
  `body_text`      MEDIUMTEXT NULL,
  `status`         VARCHAR(15) NOT NULL DEFAULT 'nuevo',          -- ver arriba
  `discard_reason` VARCHAR(60) NULL,                              -- remitente | aviso_automatico | sin_senal | no_es_instruccion | respuesta_duda
  `tipo`           VARCHAR(30) NULL,                              -- tipo de solicitud según la IA
  `flow`           VARCHAR(12) NULL,                              -- impo | expo | otro | desconocido
  `extract_json`   MEDIUMTEXT NULL,                               -- lo que entendió la IA (se reutiliza: no se vuelve a pagar)
  `result_json`    MEDIUMTEXT NULL,                               -- última decisión tomada
  `tries`          TINYINT NOT NULL DEFAULT 0,
  `error`          VARCHAR(500) NULL,
  `processed`      DATETIME NULL,
  `content_purged` DATETIME NULL,                                 -- cuándo se vaciaron cuerpo y adjuntos (retención)
  `created`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`message_id`),
  UNIQUE KEY `uq_tenant_rfc` (`tenant_id`, `rfc_message_id`(190)),
  KEY `idx_status` (`tenant_id`, `status`),
  KEY `idx_received` (`received`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ndb_agent_attachments` (
  `attachment_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT UNSIGNED NOT NULL,
  `message_id`    INT UNSIGNED NOT NULL,
  `filename`      VARCHAR(255) NOT NULL,
  `mime`          VARCHAR(100) NULL,
  `size_bytes`    INT UNSIGNED NOT NULL DEFAULT 0,
  `sha1`          CHAR(40) NULL,
  `part_ref`      VARCHAR(255) NULL,                              -- attachmentId de Gmail o nº de parte IMAP
  `read_method`   VARCHAR(12) NULL,                               -- texto_pdf | ocr | texto | ninguno
  `text_extracted` MEDIUMTEXT NULL,
  `validation_json` TEXT NULL,                                    -- contenedores ISO válidos, booking, despacho, motivos
  `valid`         TINYINT(1) NOT NULL DEFAULT 0,                  -- 1 = el texto basta; 0 = ilegible: la IA podrá pedir el original
  `file_path`     VARCHAR(255) NULL,                              -- original guardado en storage/ solo si valid = 0
  `created`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attachment_id`),
  KEY `idx_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Un caso = una carga (contenedor / booking / despacho) de un correo, con lo decidido.
-- item_key 'msg' = decisión a nivel de correo completo (sin cargas identificables).
CREATE TABLE IF NOT EXISTS `ndb_agent_cases` (
  `case_id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`      INT UNSIGNED NOT NULL,
  `message_id`     INT UNSIGNED NOT NULL,
  `item_key`       VARCHAR(80) NOT NULL,
  `received`       DATETIME NOT NULL,
  `container`      VARCHAR(30) NULL,
  `booking`        VARCHAR(40) NULL,
  `shipment_ref`   VARCHAR(40) NULL,                              -- despacho
  `flow`           VARCHAR(12) NULL,                              -- impo | expo | otro | desconocido
  `data_json`      MEDIUMTEXT NULL,                               -- datos resueltos: lugares.{inicio|cliente|final}[] con place_id, método y confianza
  `state`          VARCHAR(12) NOT NULL DEFAULT 'pendiente',      -- pendiente | decidido | cruzado | ignorado
  `decided`        DATETIME NULL,
  `order_id`       VARCHAR(60) NULL,                              -- id de la orden en el sistema externo (lo entrega el MCP)
  `cross_state`    VARCHAR(20) NULL,                              -- resultado del último cruce (ver nomenclatura)
  `cross_json`     MEDIUMTEXT NULL,                               -- detalle del último cruce
  `cross_checked`  DATETIME NULL,                                 -- último cruce
  `cross_done`     TINYINT(1) NOT NULL DEFAULT 0,                 -- 1 = no se vuelve a cruzar
  `updated`        DATETIME NOT NULL,
  PRIMARY KEY (`case_id`),
  UNIQUE KEY `uq_message_item` (`message_id`, `item_key`),
  KEY `idx_state` (`tenant_id`, `state`),
  KEY `idx_cross` (`tenant_id`, `state`, `cross_done`, `received`),
  KEY `idx_container` (`container`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dudas para el usuario autorizado. canon identifica el dato dudoso:
--   lugar|<texto normalizado>              (compartida entre correos: se responde una vez)
--   m<message_id>|<kind>|<campo>|<item>    (propia de un correo)
CREATE TABLE IF NOT EXISTS `ndb_agent_questions` (
  `question_id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`       INT UNSIGNED NOT NULL,
  `token`           VARCHAR(16) NOT NULL,                         -- código corto visible en el asunto
  `kind`            VARCHAR(12) NOT NULL,                         -- lugar | carga | faltante | dato | conflicto
  `level`           VARCHAR(12) NOT NULL DEFAULT 'abierta',       -- confirmacion (un clic) | abierta
  `field`           VARCHAR(40) NULL,                             -- inicio | cliente | final | identificador | fecha_retiro | ...
  `flow`            VARCHAR(12) NULL,                             -- impo | expo | otro: da el nombre operativo del punto
  `canon`           VARCHAR(255) NOT NULL,
  `text`            VARCHAR(255) NULL,                            -- lo que dice el correo
  `context`         VARCHAR(500) NULL,                            -- frase donde aparece
  `source`          VARCHAR(120) NULL,                            -- asunto / cuerpo / nombre del adjunto
  `message_id`      INT UNSIGNED NULL,
  `candidates_json` TEXT NULL,                                    -- opciones sugeridas [{place_id,nombre,direccion,similitud}]
  `proposal_place_id` VARCHAR(60) NULL,                           -- propuesta de la IA (nivel confirmacion)
  `proposal_conf`   VARCHAR(8) NULL,                              -- alta | media | baja
  `status`          VARCHAR(12) NOT NULL DEFAULT 'nueva',         -- nueva | enviada | respondida
  `created`         DATETIME NOT NULL,
  `sent`            DATETIME NULL,
  `answered`        DATETIME NULL,
  `answered_by`     INT UNSIGNED NULL,                            -- recipient_id
  `answered_via`    VARCHAR(10) NULL,                             -- enlace | correo
  `answer_json`     TEXT NULL,                                    -- {"tipo":"valor|ignorar|confirmar","valor":"...","place_id":"...","recordar":true|false}
  `link_used`       TINYINT(1) NOT NULL DEFAULT 0,                -- enlace de un solo uso
  `link_used_at`    DATETIME NULL,
  PRIMARY KEY (`question_id`),
  KEY `idx_status` (`tenant_id`, `status`),
  KEY `idx_canon` (`tenant_id`, `canon`(150))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ndb_agent_question_cases` (
  `question_id` INT UNSIGNED NOT NULL,
  `case_id`     INT UNSIGNED NOT NULL,
  PRIMARY KEY (`question_id`, `case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Equivalencias: texto del correo (normalizado) -> lugar del catálogo. Se consultan antes de volver a preguntar.
-- Solo se guardan textos no genéricos (regla de negocio). `health` baja cuando el sistema externo contradice la equivalencia.
CREATE TABLE IF NOT EXISTS `ndb_agent_aliases` (
  `alias_id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`      INT UNSIGNED NOT NULL,
  `kind`           VARCHAR(20) NOT NULL DEFAULT 'lugar',
  `scope`          VARCHAR(20) NOT NULL DEFAULT 'tenant',         -- tenant | remitente
  `scope_value`    VARCHAR(150) NOT NULL DEFAULT '',
  `key_norm`       VARCHAR(255) NOT NULL,                         -- texto del correo normalizado
  `place_id`       VARCHAR(60) NULL,                              -- id del lugar en el catálogo (NULL si solo hay nombre)
  `value`          VARCHAR(255) NOT NULL,                         -- nombre del lugar o '__IGNORAR__'
  `source`         VARCHAR(20) NOT NULL DEFAULT 'usuario',        -- usuario | semilla | regla
  `question_id`    INT UNSIGNED NULL,
  `uses`           INT NOT NULL DEFAULT 0,
  `contradictions` INT NOT NULL DEFAULT 0,                        -- veces que se cambió o que el sistema externo la contradijo
  `health`         TINYINT NOT NULL DEFAULT 100,                  -- 0-100; bajo el umbral pide revisión
  `needs_review`   TINYINT(1) NOT NULL DEFAULT 0,
  `active`         TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`     INT UNSIGNED NULL,
  `created`        DATETIME NOT NULL,
  `updated`        DATETIME NOT NULL,
  PRIMARY KEY (`alias_id`),
  KEY `idx_lookup` (`tenant_id`, `kind`, `key_norm`(150), `active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ndb_agent_runs` (
  `run_id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NULL,
  `process`    VARCHAR(30) NOT NULL,                              -- run
  `started`    DATETIME NOT NULL,
  `finished`   DATETIME NULL,
  `stats_json` TEXT NULL,
  `error`      VARCHAR(500) NULL,
  PRIMARY KEY (`run_id`),
  KEY `idx_started` (`started`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ndb_agent_usage` (
  `usage_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL,
  `created`    DATETIME NOT NULL,
  `purpose`    VARCHAR(20) NOT NULL,                              -- extract | extract_visual | resolve
  `model`      VARCHAR(60) NOT NULL,
  `tokens_in`  INT NOT NULL,
  `tokens_out` INT NOT NULL,
  `cost_usd`   DECIMAL(10,5) NOT NULL,
  `message_id` INT UNSIGNED NULL,
  PRIMARY KEY (`usage_id`),
  KEY `idx_created` (`tenant_id`, `created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bitácora de correos salientes (dudas, avisos, resumen). status: enviado | simulado (dry_run) | error
CREATE TABLE IF NOT EXISTS `ndb_agent_outbox` (
  `outbox_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL,
  `kind`         VARCHAR(20) NOT NULL DEFAULT 'duda',            -- duda | aviso | resumen | respuesta | enlace_vencido
  `question_id`  INT UNSIGNED NULL,
  `recipient_id` INT UNSIGNED NULL,
  `to_email`     VARCHAR(150) NOT NULL,
  `subject`      VARCHAR(255) NOT NULL,
  `status`       VARCHAR(12) NOT NULL,
  `error`        VARCHAR(500) NULL,
  `preview_path` VARCHAR(255) NULL,
  `created`      DATETIME NOT NULL,
  PRIMARY KEY (`outbox_id`),
  KEY `idx_question` (`question_id`, `status`),
  KEY `idx_created` (`tenant_id`, `created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Avisos del cruce con el sistema externo. Salen en un resumen por hora (revalidados justo antes de enviar)
-- o solo en el resumen diario. `sig` evita avisar dos veces lo mismo.
CREATE TABLE IF NOT EXISTS `ndb_agent_alerts` (
  `alert_id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL,
  `case_id`      INT UNSIGNED NOT NULL,
  `kind`         VARCHAR(24) NOT NULL,                            -- falta_en_sistema | lugar_vacio | distinto
  `point`        VARCHAR(10) NULL,                                -- inicio | cliente | final
  `flow`         VARCHAR(12) NULL,
  `mail_value`   VARCHAR(255) NULL,                               -- lo que dice el correo
  `system_value` VARCHAR(255) NULL,                               -- lo que dice el sistema externo
  `order_id`     VARCHAR(60) NULL,
  `source`       VARCHAR(120) NULL,                               -- de dónde sale el dato (asunto / cuerpo / adjunto)
  `executed`     TINYINT(1) NOT NULL DEFAULT 0,                   -- el tramo ya lo ejecutó el camión
  `sig`          CHAR(40) NOT NULL,                               -- sha1(tenant|caso|tipo|punto|valores)
  `status`       VARCHAR(24) NOT NULL DEFAULT 'pendiente',        -- pendiente | enviado | resumen_diario | descartado_revalidado
  `created`      DATETIME NOT NULL,
  `notified`     DATETIME NULL,
  `outbox_id`    INT UNSIGNED NULL,
  `feedback`     TINYINT NULL,                                    -- 1 = ✓ útil, 0 = ✗ no útil (mide la calidad)
  `feedback_by`  INT UNSIGNED NULL,
  `feedback_at`  DATETIME NULL,
  PRIMARY KEY (`alert_id`),
  UNIQUE KEY `uq_sig` (`tenant_id`, `sig`),
  KEY `idx_status` (`tenant_id`, `status`, `created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Una fila por llamada MCP: sirve para comprobar que se consulta por lotes (items >> calls).
CREATE TABLE IF NOT EXISTS `ndb_agent_mcp_log` (
  `log_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `created`   DATETIME NOT NULL,
  `tool`      VARCHAR(40) NOT NULL,                               -- catalogo_lugares | buscar_ordenes | tramos_de_ordenes | revalidar_avisos
  `items`     INT NOT NULL,                                       -- elementos enviados en la llamada
  `ok_items`  INT NOT NULL DEFAULT 0,
  `ms`        INT NOT NULL DEFAULT 0,
  `error`     VARCHAR(300) NULL,
  `process`   VARCHAR(30) NULL,                                   -- cron que la originó
  PRIMARY KEY (`log_id`),
  KEY `idx_created` (`tenant_id`, `created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Acciones ejecutadas a partir de decisiones (reglas/acciones.json). La clave única hace cada acción idempotente.
CREATE TABLE IF NOT EXISTS `ndb_agent_actions` (
  `action_id`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL,
  `decision`   VARCHAR(30) NOT NULL,                              -- ver reglas/acciones.json
  `action`     VARCHAR(30) NOT NULL,
  `ref`        VARCHAR(190) NOT NULL,                             -- objeto afectado: m12|c34|q56|a78
  `detail_json` TEXT NULL,
  `created`    DATETIME NOT NULL,
  PRIMARY KEY (`action_id`),
  UNIQUE KEY `uq_action` (`tenant_id`, `decision`, `action`, `ref`(150)),
  KEY `idx_created` (`tenant_id`, `created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
