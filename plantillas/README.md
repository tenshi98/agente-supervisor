# Plantillas de correo

Correos que envía el agente. Las renderiza `src/Notify/Templates.php`. Vocabulario: `reglas/00_nomenclatura.md`.
Sin marca fija: el nombre y el pie salen de la configuración del tenant (`brand_name`, `brand_footer`).

## Archivos

Por cada plantilla: `<nombre>.html` (se inserta en `_layout.html`), `<nombre>.txt` (texto plano) y `<nombre>.subject.txt` (asunto, una línea).

```php
$t = new Templates(__DIR__ . '/plantillas', ['brand_name' => '', 'brand_footer' => '', 'dry_run' => true, 'base_url' => 'https://…']);
$mail = $t->render('duda_abierta', $vars);   // ['subject' => …, 'html' => …, 'text' => …]
$t->missing();                               // variables usadas y no entregadas (para pruebas)
```

## Sintaxis

| Forma | Efecto |
|---|---|
| `{{var}}` | valor **escapado** (HTML en `.html`/layout; sin escapar en `.txt` y asunto) |
| `{{{var}}}` | valor crudo: solo para HTML ya armado y escapado por el código |
| `{{a.b}}`, `{{this}}`, `{{this.campo}}` | ruta con puntos; dentro de `each`, `this` es el elemento y se ven también las variables exteriores |
| `{{@index}}`, `{{@index1}}` | posición en el `each` (desde 0 / desde 1) |
| `{{#if x}}…{{else}}…{{/if}}` | vacío, `0`, `false`, `null` y `[]` son falsos. Una condición **nunca** cuenta como variable faltante: así se declara un dato opcional |
| `{{#each lista}}…{{/each}}` | repite por elemento; todo es anidable |

Variable ausente en `{{}}` = cadena vacía (no falla) y queda en `missing()`.

## Reglas de contenido

1. Todo aviso dice **qué pasó**, **de dónde sale el dato** (asunto, cuerpo o nombre del adjunto) y **qué hacer**.
2. Vocabulario de operaciones según el flujo (`point_label`: Retiro en puerto / Entrega al cliente / Devolución del vacío en `impo`; Retiro del vacío / Carga en planta / Entrega en puerto en `expo`). Nunca `inicio/cliente/final` ni nombres de columnas.
3. Todo texto que venga de un correo externo va con `{{}}` (escapado). `{{{}}}` solo para HTML armado por el código.
4. Sin marca, nombres de sistemas ni imágenes externas dentro de los archivos.
5. Dudas: asunto `[Duda D-0001·token] título`. Los botones `mailto:` responden a `reply_to` con `subject=Re: [Duda D-NNNN·token] …` y `body`: `1`…`n` (opción), `1 R` (opción y **recordar**), `N` (no está / no es este), `0` (ignorar) o texto libre. El enlace firmado (`url`) es la vía principal.
6. `can_remember` es falso para textos genéricos (ciudades, zonas): nunca se ofrece "recordar".

## Variables comunes (las agrega `Templates` o el código a todas)

`brand_name`, `brand_footer` (strings, opcionales), `dry_run` (bool: muestra la etiqueta PRUEBA), `base_url`, `subject` (el asunto ya renderizado, lo usa el layout). Opcional en el layout: `preheader`.

## Variables por plantilla

### Dudas (`duda_confirmacion`, `duda_abierta`, `duda_carga`, `duda_faltante`, `duda_conflicto`)

Comunes a las cinco:

| Variable | Tipo | Descripción |
|---|---|---|
| `question_id` | string | `D-0001` |
| `token` | string | código corto del asunto |
| `title` | string | título de la duda (`QuestionText::describe`) |
| `url` | string | enlace firmado de un solo uso |
| `ttl_hours` | int | vigencia del enlace |
| `reply_to` | string | casilla que lee las respuestas |
| `mailto_subject` | string | `rawurlencode("Re: [Duda D-0001·token] título")` |
| `source`, `context` | string, opc. | dónde lo dice (asunto/cuerpo/adjunto) y la frase |
| `message_subject`, `message_sender`, `message_received` | string, opc. | correo que originó la duda |
| `also_count` | int, opc. | otras cargas con el mismo texto |

Específicas:

| Plantilla | Variables |
|---|---|
| `duda_confirmacion` | `text` (lo que dice el correo), `point_label`, `identifier` (opc.), `proposal` `{name, address?}`, `can_remember` (bool) |
| `duda_abierta` | `text`, `point_label`, `identifier` (opc.), `candidates` lista `{n, name, address?, badge?}`, `can_remember` |
| `duda_carga` | `identifier`, `reason` (opc.), `items` lista `{label, value}` (lo leído de la carga) |
| `duda_faltante` | `lead` (explicación), `field_label` (nombre operativo del dato), `identifier` (opc.), `text` (opc., valor inválido leído) |
| `duda_conflicto` | `text`, `point_label`, `options` lista `{n, name, origin}` (p. ej. «Decisión anterior» / «Regla actual») |

### `aviso_hora` (resumen por hora, canal `aviso`)

| Variable | Tipo | Descripción |
|---|---|---|
| `date`, `hour` | string | fecha y hora local del resumen |
| `total` | int | avisos en el resumen |
| `counts` | `{falta_en_sistema, lugar_vacio, distinto}` | totales por insignia (Sin orden / Falta cargar / No coincide) |
| `alerts` | lista | un elemento por aviso (ya revalidado): |
| `alerts[].badge`, `badge_bg` | string | etiqueta («Sin orden», «Falta cargar», «No coincide») y color |
| `alerts[].identifier` | string | contenedor, booking o despacho |
| `alerts[].order_id` | string, opc. | orden en el sistema externo |
| `alerts[].what_happened` | string | qué pasó |
| `alerts[].point_label` | string | punto con vocabulario de operaciones |
| `alerts[].mail_value`, `system_value` | string, opc. | lo que dice el correo / el sistema |
| `alerts[].source` | string | de dónde sale el dato |
| `alerts[].what_to_do` | string | qué hacer |
| `alerts[].feedback_ok_url`, `feedback_no_url` | string | ✓ / ✗ para medir calidad |

### `resumen_diario` (19:00, canal `resumen`)

| Variable | Descripción |
|---|---|
| `date` | fecha |
| `totals` | `{mails, instructions, cases, automatic, questions_sent, questions_pending, alerts_sent, revalidated, aliases_new}` |
| `informatives` | lista `{identifier, order_id?, point_label, mail_value, system_value, source, note?}`: diferencias en tramos ya ejecutados |
| `pending` | lista `{id, title, age, url}`: dudas sin responder |
| `ai` | `{cost_usd, calls, tokens_in, tokens_out}` |
| `mcp` | `{calls, items}` (consultas por lote y elementos consultados) |

### `respuesta_recibida`

Comunes de dudas más: `answer_text`, `answered_by` (opc.), `effect` (qué pasa ahora), `remembered` (bool), `text` (opc.).

### `enlace_vencido`

Comunes de dudas más: `expired_at`.
