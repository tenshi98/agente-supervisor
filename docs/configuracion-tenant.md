# Configuración por tenant (`ndb_agent_config`)

Cada tenant (empresa) tiene su propia fila en `ndb_agent_config`. Se edita con `tools/tenant.php`. El tenant inicial queda **apagado** y con `dry_run=1`.

```bash
php tools/tenant.php --tenant=1 --active=1 --dry-run=0
php tools/tenant.php --tenant=1 --grace-min=60 --window-hours=72 --notify-every-min=60 --summary-hour=19 --retention-days=90
php tools/tenant.php --tenant=1 --max-run=8 --max-day=30 --ai-max-usd=5 --ttl-hours=72
php tools/tenant.php --tenant=1 --brand-name="Mi empresa" --brand-footer="Servicio de supervisión de operaciones"
```

La marca sale de `brand_name`/`brand_footer`; si están vacíos, los correos no llevan marca.

| Campo | Defecto | Qué controla |
|---|---|---|
| `active` | 0 | el cron ignora al tenant si es 0 |
| `dry_run` | 1 | 1 = no envía correos, deja vistas previas en `storage/outbox/` |
| `max_questions_run` / `max_questions_day` | 8 / 30 | tope de dudas enviadas por corrida y por día |
| `llm_model`, `ai_max_usd_day` | `claude-sonnet-5-5`, 5.00 | modelo de IA y tope diario de gasto |
| `link_ttl_hours` | 72 | vigencia del enlace de una duda |
| `grace_min` / `window_hours` | 60 / 72 | el cruce con el sistema externo empieza tras la gracia y dura hasta la ventana |
| `check_every_min`, `notify_every_min` | 15, 60 | frecuencias de cruce y de resumen de avisos |
| `summary_hour` | 19 | hora local del resumen diario |
| `retention_days` | 90 | días que se conserva el contenido de los correos |
| `brand_name`, `brand_footer` | vacío | marca de las plantillas (vacío = sin marca) |

---

[← Volver al README](../README.md)
