# Reglas editables (`reglas/`)

Las reglas se leen en cada corrida: editar y guardar basta. **Son un borrador inicial: ajústalas a tu operación.**

| Archivo | Contenido |
|---|---|
| `00_nomenclatura.md` | **glosario oficial**: puntos `inicio/cliente/final`, flujo, estados, niveles, métodos de resolución, roles, mapeo con el sistema externo |
| `01_rol.md`, `02_campos.md`, `03_decisiones.md` | instrucciones a la IA (se envían con caché) |
| `decision.json` | prefiltro, tipos que se procesan, niveles de confianza, campos obligatorios, validaciones, umbrales de candidatos, topes de dudas, equivalencias, **cruce** (gracia, ventana, «cuándo hace falta»), avisos, retención y límites de IA |
| `acciones.json` | qué acción produce cada decisión (ver «Decisiones y acciones» más abajo) |
| `lugares.json` | genéricos con su zona, zonas (centro y radio), marcas con varias sedes, reglas de dirección («dpw» → Puerto Central) y equivalencias semilla |

Ejemplo de campo obligatorio: `"campos_obligatorios": {"instruccion_transporte": ["identificador", "lugares.inicio", "fechas.retiro"]}` (por defecto solo se exigen `identificador` y `lugares.inicio`).

## Decisiones y acciones (`acciones.json`)

Niveles: `automatica` (confianza alta), `confirmacion` (media, duda de un clic) y `abierta` (baja o sin candidato). Las decisiones generan acciones; cada acción queda en `ndb_agent_actions`, que la hace idempotente.

| Decisión | Condición | Acción |
|---|---|---|
| `descartar` | remitente fuera, sin señal o no es instrucción | marcar `descartado` |
| `automatica` | confianza alta | guardar caso y encolar cruce |
| `confirmacion` / `abierta` | confianza media / baja | guardar caso y crear duda |
| `carga_dudosa`, `faltante`, `dato_invalido`, `conflicto` | la IA dudó de la carga; falta un dato; dato inválido; equivalencia contradice una regla | guardar caso y crear duda |
| `limite_dudas` | tope por corrida o por día | diferir la duda |
| `respuesta_confirmar` / `respuesta_valor` | el destinatario confirma o elige | guardar equivalencia (solo con «recordar») y reprocesar |
| `respuesta_ignorar` | el destinatario ignora | ignorar el caso y reprocesar |
| `cruce_sin_orden` | no hay orden pasada la gracia | aviso `falta_en_sistema` |
| `cruce_lugar_vacio` | lugar vacío que ya hace falta | aviso `lugar_vacio` |
| `cruce_distinto` | difiere y el tramo no se ejecutó | aviso `distinto` |
| `cruce_distinto_ejecutado` | difiere pero el camión ya ejecutó el tramo | solo resumen diario |
| `cruce_ok`, `cruce_sin_verificar` | coincide, aún no hace falta o no se puede verificar | ninguna |
| `revalidado` | el sistema ya trae el dato antes de enviar | descartar el aviso en silencio |

## Tipos de duda

| `kind` | Se genera cuando | Plantilla |
|---|---|---|
| `lugar` | el texto de un lugar no se resolvió solo (confirmación o abierta) | `duda_confirmacion` / `duda_abierta` |
| `conflicto` | una equivalencia guardada contradice una regla | `duda_conflicto` |
| `carga` | la IA declaró confianza baja sobre una carga, o sobre si el correo es una instrucción | `duda_carga` |
| `faltante` | falta un dato obligatorio (`reglas/decision.json`) | `duda_faltante` |
| `dato` | contenedor con dígito verificador ISO 6346 erróneo o fecha inválida | `duda_faltante` |

Un mismo lugar se pregunta **una sola vez** aunque aparezca en varios correos.

---

[← Volver al README](../README.md)
