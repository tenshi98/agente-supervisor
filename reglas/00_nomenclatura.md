# Nomenclatura oficial

Fuente única de los nombres que usan las reglas, el código, las tablas, las plantillas y el contrato MCP.
Si un nombre no está aquí, no se usa. Los valores van **sin tildes y en minúscula** (`snake_case`) salvo que se indique.

> Este archivo se envía a la IA junto con las demás reglas: solo debe contener vocabulario, no instrucciones de negocio.

## 1. Los tres puntos de una carga

| Punto | Significado genérico |
|---|---|
| `inicio` | Donde empieza el recorrido de la carga |
| `cliente` | Instalación del cliente (entrega o carga) |
| `final` | Donde termina el recorrido |

Etiquetas que ve una persona, según el flujo (**siempre** el vocabulario de operaciones):

| Punto | `impo` (importación) | `expo` (exportación) | `otro` |
|---|---|---|---|
| `inicio` | Retiro en puerto | Retiro del vacío | Retiro |
| `cliente` | Entrega al cliente | Carga en planta del cliente | Cliente |
| `final` | Devolución del vacío | Entrega en puerto | Destino final |

Descartados: `origen` (→ `inicio`), `destino` (→ `final`), `start/delivery/end` (solo existen en el mapeo del sistema externo, sección 9).

## 2. Flujo

`impo` · `expo` · `otro` · `desconocido` (la IA no lo pudo determinar).

## 3. Identificadores de carga

| Nombre | Qué es |
|---|---|
| `contenedor` | Número ISO 6346 (4 letras + 7 dígitos) |
| `booking` | Reserva de la naviera |
| `despacho` | Número de despacho |
| `item_key` | Clave de la carga dentro de un correo: `contenedor`, `bk:<booking>`, `ds:<despacho>`, `n<N>` o `msg` |

Una carga se busca en este orden: contenedor, despacho, booking.

## 4. Tipo de solicitud (lo decide la IA)

`instruccion_transporte` · `aviso` · `consulta` · `facturacion` · `otro`

## 5. Estados

| Objeto | Valores |
|---|---|
| Correo | `listo` → `procesado` · `pendiente` (hay dudas) · `descartado` · `error` |
| Motivo de descarte | `remitente` · `aviso_automatico` · `sin_senal` · `no_es_instruccion` · `respuesta_duda` (era la respuesta a una duda) |
| Carga (caso) | `pendiente` → `decidido` → `cruzado` · `ignorado` |
| Duda | `nueva` → `enviada` → `respondida` (si vence, vuelve a `nueva`) |
| Aviso | `pendiente` → `enviado` · `resumen_diario` · `descartado_revalidado` |
| Corrida | `ok` · `error` |

## 6. Nivel de decisión

| Nivel | Cuándo | Qué se hace |
|---|---|---|
| `automatica` | confianza **alta** | se acepta sin preguntar |
| `confirmacion` | confianza **media** | duda de un clic: "¿es este?" |
| `abierta` | confianza **baja** o sin candidato | duda abierta con candidatos y texto libre |

Confianza: `baja` < `media` < `alta`. Lo no reconocido vale `baja`.

## 7. Resolución de lugares

Método con que se resolvió un lugar, en orden de prioridad:
`equivalencia` (guardada) → `generico` (ciudad/zona, no se pregunta) → `exacto` → `parecido` → `ia` (elige entre candidatos o "ninguno") → `marca` (cliente con varias sedes).

| Nombre | Qué es |
|---|---|
| `equivalencia` | Texto del correo ↔ lugar del catálogo, guardado; antes "memoria"/"alias" |
| `generico` | Texto de ciudad o zona (`san antonio`, `valparaiso`, `santiago`); se verifica por zona |
| `marca` | Cliente con ≥ 2 sedes activas (Agrosuper, Walmart); se verifica por marca |
| `candidato` | Lugar activo del catálogo propuesto para un texto |
| `place_id` | Identificador del lugar en el catálogo del sistema externo |
| `recordar` | Respuesta que convierte una decisión en equivalencia permanente (solo textos no genéricos) |

## 8. Dudas, avisos y destinatarios

| Nombre | Valores |
|---|---|
| Tipo de duda (`kind`) | `lugar` · `carga` · `faltante` · `dato` · `conflicto` |
| Campo (`field`) | `inicio` · `cliente` · `final` · `identificador` · `contenedor` · `fecha_retiro` · `fecha_entrega` · `fecha_devolucion` · `fecha_stacking` · `carga` · `tipo` |
| Respuesta | `confirmar` · `candidato:N` · `valor` · `ignorar` (+ `recordar`) |
| Tipo de aviso | `falta_en_sistema` (insignia "Sin orden") · `lugar_vacio` ("Falta cargar") · `distinto` ("No coincide") |
| Resultado del cruce | `ok` · `ok_en_ruta` · `ok_almacenaje` · `aun_no_requerido` · `falta_en_sistema` · `lugar_vacio` · `distinto` · `distinto_ejecutado` · `no_encontrada` · `esperando_duda` · `no_verificable` |
| Rol de destinatario | `duda` (decide, al momento) · `aviso` (resumen por hora) · `resumen` (diario 19:00) |
| Modo | `dry_run` = 1 → no envía correos (deja vistas previas); antes "modo prueba"/"modo sombra" |

"Usuario autorizado", "jefe" y "supervisor" son sinónimos descartados: se dice **destinatario con rol `duda`**.

## 9. Mapeo con el sistema externo (configurable)

El agente no conoce un sistema concreto. Lo que devuelve el MCP se mapea a estos nombres; el detalle de cada herramienta está en `docs/mcp.md`.

| Agente | Campo del sistema externo (ejemplo) |
|---|---|
| `inicio` / `cliente` / `final` | `start_name` / `delivery_name` / `end_name` |
| `despacho` | `shipment` en `impo` |
| `booking` | `reference` en `impo`; `shipment` en `expo` |
| tramo hacia almacenaje / cliente / final | `status_id` 10–12 / 20–22 / 30–31 |

## 10. Nombres técnicos

Tablas `ndb_agent_*`; crons `agent_ingest`, `agent_check`, `agent_notify`, `agent_summary`, `agent_maintenance`; herramientas MCP `catalogo_lugares`, `buscar_ordenes`, `tramos_de_ordenes`, `revalidar_avisos`; fechas en UTC en la BD, hora del tenant al mostrar.
