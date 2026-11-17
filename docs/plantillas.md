# Plantillas de correo (`plantillas/`)

Los correos que envía el agente (dudas, avisos, resumen) salen de plantillas editables, sin marca fija: el nombre y el pie vienen de `brand_name`/`brand_footer` del tenant ([configuracion-tenant.md](configuracion-tenant.md)).

HTML + texto + asunto por tipo, con `{{variables}}` escapadas: `duda_confirmacion`, `duda_abierta`, `duda_carga`, `duda_faltante`, `duda_conflicto`, `aviso_hora`, `resumen_diario`, `respuesta_recibida`, `enlace_vencido`. Sintaxis, variables y reglas de contenido en [plantillas/README.md](../plantillas/README.md).

| Plantilla | Se envía cuando | Rol que la recibe |
|---|---|---|
| `duda_confirmacion` | un lugar tiene propuesta (confianza media): un clic | `duda` |
| `duda_abierta` | un lugar sin propuesta: candidatos y texto libre | `duda` |
| `duda_carga` | la IA dudó de la carga o del tipo de correo | `duda` |
| `duda_faltante` | falta un dato obligatorio o es inválido | `duda` |
| `duda_conflicto` | una equivalencia contradice una regla | `duda` |
| `aviso_hora` | resumen por hora de avisos (revalidado) | `aviso` |
| `resumen_diario` | resumen de las 19:00 | `resumen` |
| `respuesta_recibida` | confirmación al decisor | `duda` |
| `enlace_vencido` | reenvío con enlace nuevo | `duda` |

Todo aviso dice **qué pasó**, **de dónde sale el dato** y **qué hacer**, con el vocabulario de operaciones según el flujo ([../reglas/00_nomenclatura.md](../reglas/00_nomenclatura.md)).

---

[← Volver al README](../README.md)
