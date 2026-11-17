# Seguridad

Medidas de seguridad del agente.

- `.htaccess` de la raíz cierra todo; `public/.htaccess` abre solo `public/` (con `AllowOverride All`, o `config/.env` quedaría expuesto).
- Páginas públicas, todas con CSP estricta, sin JavaScript, verificación de origen en POST y todo texto externo escapado: `index.php` (dudas, enlace de un solo uso), `aviso.php` (✓/✗ de un aviso, de un solo uso), `panel.php` (solo lectura, enlace con vencimiento y propósito propio), `gmail.php` (conexión de casillas).
- Los enlaces llevan un **propósito** dentro de la firma: uno no sirve para otro fin.
- Destinatarios **solo desde la BD**: el contenido de un correo nunca decide a quién se escribe.
- El contenido de los correos es **dato, no instrucciones** (también se le indica así a la IA). El agente solo escribe en `ndb_agent_*` y consulta el sistema externo en solo lectura.
- **Retención**: cuerpo y texto de adjuntos se vacían a los 90 días (configurable).
- El token del servidor MCP (`MCP_TOKEN`) vive solo en `config/.env`; nunca en logs ni en `ndb_agent_mcp_log` ([mcp.md](mcp.md)).

---

[← Volver al README](../README.md)
