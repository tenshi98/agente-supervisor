# Qué extraer

## Del correo completo

- `tipo_solicitud`: una de
  - `instruccion_transporte`: pide o programa un retiro, entrega o devolución de contenedores o cargas.
  - `aviso`: informa algo sin pedir acción (llegada de nave, demurrage, citas, reportes, stock).
  - `consulta`: una pregunta o solicitud de información.
  - `facturacion`: facturas, cobros, tarjetones.
  - `otro`: cualquier cosa distinta.
- `flujo`: `impo` (importación: el contenedor llega lleno y se entrega al cliente), `expo` (exportación: se retira un vacío, se carga en la planta del cliente y se entrega en puerto), `otro` o `desconocido`.
- `relevante`: `true` si el correo trae datos de una carga que el agente debe registrar.
- `confianza`: `alta`, `media` o `baja` sobre tu lectura general.
- `legible`: `false` si el contenido está cortado, ilegible o sin sentido.
- `necesita_original`: lista de nombres de adjuntos cuyo texto automático no basta (ilegibles o incomprensibles). Vacía si todo se entendió.
- `notas`: una frase si algo es raro; si no, vacío.

## Por cada carga (`cargas`)

Una entrada por contenedor (número ISO 6346: 4 letras + 7 dígitos, ej. `ABCU1234567`), por booking o por número de despacho. Si los lugares y fechas aplican a todas, repítelos en cada una.

- `contenedor`, `booking`, `despacho`: tal como aparecen; vacío si no hay.
- `lugares`: tres puntos (ver `00_nomenclatura.md`), cada uno una lista de menciones `{texto, contexto, fuente}`:
  - `inicio`: donde se retira el contenedor o la carga.
  - `cliente`: instalación del cliente donde se entrega o se carga.
  - `final`: donde se deja el contenedor o la carga al terminar.
  - Si un punto trae varias opciones escritas ("DPW o STI"), una mención por cada una.
  - `contexto`: la frase corta del correo donde aparece. `fuente`: `asunto`, `cuerpo` o el nombre exacto del adjunto.
- `fechas`: `retiro`, `entrega`, `devolucion`, `stacking`. Formato `AAAA-MM-DD` o `AAAA-MM-DD HH:MM`; vacío si no hay. No inventes el año: si falta y no se deduce del correo, déjalo vacío.
- `confianza`: `alta`, `media` o `baja` sobre esa carga.

## Todo lo demás (`datos_adicionales`)

Cualquier otro dato útil del correo o de los adjuntos (naviera, nave, peso, sello, temperatura, contacto, observaciones…) como `{clave, valor, fuente}`.

## Formato de salida

```json
{
  "tipo_solicitud": "instruccion_transporte",
  "flujo": "impo",
  "relevante": true,
  "confianza": "alta",
  "legible": true,
  "necesita_original": [],
  "cargas": [
    {
      "contenedor": "", "booking": "", "despacho": "",
      "lugares": {
        "inicio":  [{"texto": "", "contexto": "", "fuente": ""}],
        "cliente": [],
        "final":   []
      },
      "fechas": {"retiro": "", "entrega": "", "devolucion": "", "stacking": ""},
      "confianza": "alta"
    }
  ],
  "datos_adicionales": [{"clave": "", "valor": "", "fuente": ""}],
  "notas": ""
}
```
