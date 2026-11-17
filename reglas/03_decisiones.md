# Criterios al leer

- Un nombre que acompaña a un booking (naviera, agencia) **no** es un lugar.
- Depósitos, extraportuarios y terminales portuarias no son el `cliente`.
- Una "ventana de stacking" es una fecha (`stacking`), no un lugar de almacenaje.
- Reportes de stock, cuadres, control reefer, horas reales, sobreestadía, tarjetones y facturas **no** son instrucciones de transporte: `relevante` = `false` salvo que pidan una acción de transporte.
- Si el mismo dato aparece distinto en el cuerpo y en un adjunto, usa el del adjunto más reciente y deja la discrepancia en `notas` con confianza `media` o `baja`.
- Ante la duda entre dos lecturas, elige la más conservadora y baja la confianza.
- "DPW" a secas significa Puerto Central. "DP World CIS" es otro lugar. Copia el texto tal cual; no lo reemplaces.
- En "BK 279048571 DP WORLD" el nombre es parte del booking: no es un lugar.
- Un puerto, terminal o depósito nunca es el punto `cliente`; si solo aparece uno, deja `cliente` vacío.
- Si un punto no aparece en el correo, déjalo vacío: no lo deduzcas del flujo.
- La diferencia entre dos documentos casi siempre es un cambio de plan, no un error: anótala en `notas`, no la corrijas.
