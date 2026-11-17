# Casillas, destinatarios y equivalencias

Quién recibe cada cosa, de dónde se leen los correos y cómo se siembran las equivalencias.

```bash
# Un correo puede tener varios roles: duda (decide), aviso (resumen por hora), resumen (diario)
php tools/add_recipient.php --email=jefe@empresa.cl --name="Jefe de operaciones" --role=duda,aviso,resumen

# Casilla IMAP (la clave va por variable de entorno)
MAILBOX_SECRET='LA_CLAVE' php tools/add_mailbox.php --type=imap --email=operaciones@empresa.cl --host=imap.empresa.cl --folders=INBOX --allowed=cliente.cl

# Casilla Gmail (OAuth, ver gmail.md)
php tools/gmail_link.php --email=ops@gmail.com

# Equivalencias semilla (reglas/lugares.json)
php tools/seed_equivalences.php --tenant=1

# Enlace de solo lectura al panel (equivalencias, avisos con ✓/✗, costos de IA y MCP)
php tools/panel_link.php --tenant=1
```

Puede haber **varias casillas por tenant**. Las credenciales se guardan cifradas con `AGENT_VAULT_KEY`. Para Gmail ver [gmail.md](gmail.md).

## Roles de destinatario

| Rol | Recibe | Cuándo |
|---|---|---|
| `duda` | dudas y puede decidir | al momento |
| `aviso` | resumen de avisos | cada hora |
| `resumen` | resumen diario | 19:00 (hora del tenant) |

Un correo puede tener varios roles. Los destinatarios salen **solo de la BD**.

## Cómo se responde una duda

- **Enlace firmado de un solo uso** (vía principal): `public/index.php`. Solo responden destinatarios activos con rol `duda`. Con «recordar» (solo lugares no genéricos) la respuesta pasa a ser una equivalencia.
- **Por correo** (alternativa): los botones `mailto:` responden a `[Duda D-0012·token]` con `1`, `1 R` (y recordar), `N`, `0` o texto libre. El agente lee la respuesta en cualquiera de las casillas que ya lee (la casilla de `MAIL_REPLY_TO` debe estar entre ellas) y solo la acepta si el remitente es un destinatario `duda`.

## Filtro de remitentes

| `MAIL_ALLOWED_SENDERS` | Remitente | Resultado |
|---|---|---|
| *(vacío)* | `cualquiera@x.com` | pasa |
| `cliente.cl` | `ana@cliente.cl`, `ana@sub.cliente.cl` | pasa |
| `cliente.cl` | `ana@otro.com` | descartado: `remitente` |
| *(cualquiera)* + `MAIL_DISCARD_SENDERS=avisos@x.cl` | `avisos@x.cl` | descartado: `aviso_automatico` |

---

[← Volver al README](../README.md)
