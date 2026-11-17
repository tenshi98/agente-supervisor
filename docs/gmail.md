# Conectar una casilla Gmail

Alternativa a IMAP para casillas de Google.

Gmail usa OAuth: el **dueño de la casilla** autoriza el acceso de solo lectura y el sistema guarda el *refresh token* cifrado (nunca se muestra).

1. En Google Cloud: crea/elige un proyecto, habilita **Gmail API**, configura la pantalla de consentimiento (**Interno** para Workspace; **Externo** para cuentas personales, con las casillas como usuarios de prueba) con el alcance `gmail.readonly`.
2. *Credenciales → ID de cliente OAuth → Aplicación web*; en **URI de redireccionamiento** pon la URL que imprime `gmail_link.php` (por defecto `AGENT_BASE_URL/gmail.php`; HTTPS salvo `localhost`).
3. Copia ID y secreto a `GOOGLE_CLIENT_ID` y `GOOGLE_CLIENT_SECRET`.
4. `php tools/gmail_link.php --email=ops@gmail.com --folders=INBOX --allowed=cliente.cl` y entrega el enlace (60 min, un solo uso) al dueño.

> Con consentimiento **Externo en modo Pruebas**, el refresh token caduca a los **7 días**. Para uso permanente usa consentimiento Interno o publica la aplicación. Google Workspace con IMAP exige contraseña de aplicación u OAuth.

---

[← Volver al README](../README.md)
