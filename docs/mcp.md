# Contrato MCP con el sistema externo

El agente vive separado del sistema de gestión del cliente (órdenes, contenedores, tramos, catálogo de lugares). **Toda consulta a ese sistema pasa por MCP**, con este contrato. El contrato no menciona ningún sistema concreto: cualquier servidor que lo implemente sirve.

## 1. Regla de oro: ahorrar consultas

> **Una llamada por lote, nunca por elemento.** Si se necesitan datos de 8 elementos, se envía **una** llamada con los 8 en un arreglo `items`, y el servidor responde **una** entrada por cada uno, identificada por el mismo `ref`.

- Cada elemento enviado lleva un `ref` (texto único elegido por el agente, p. ej. `c12:inicio`).
- La respuesta trae **exactamente los mismos `ref`** que se enviaron (mismo número de elementos). Si falta uno, el cliente lo marca `error`.
- Un elemento fallido o sin resultado **no tumba el lote**: solo su entrada lleva `status` distinto de `ok`.
- El cliente (`Agente\Mcp\HttpMcpClient`) deduplica por `ref` y parte el lote en trozos de `MCP_BATCH_MAX` (50 por defecto).

## 2. Transporte

- JSON-RPC 2.0 sobre HTTPS (`POST MCP_URL`), método `tools/call`; `tools/list` para descubrir las herramientas.
- `Authorization: Bearer <MCP_TOKEN>`: un token por tenant (el servidor deduce el tenant del token).
- **Solo lectura**: ninguna herramienta modifica datos del sistema externo.
- Timeout `MCP_TIMEOUT` (30 s). Reintentos: 2 (3 intentos) ante 429, 5xx o timeout, con pausa creciente. Tras agotarlos, todos los `ref` del trozo quedan `error` (no se lanza excepción) y se anota en `ndb_agent_mcp_log`.
- Respuesta: `result.structuredContent` con `{"items":[...]}` (o, alternativamente, ese mismo JSON serializado en `result.content[0].text`).

## 3. Estados por elemento y errores

| `status` | Significa |
|---|---|
| `ok` | Se encontró y se devuelve el dato |
| `no_encontrado` | Consulta válida sin resultado (no hay orden, no hay candidatos) |
| `error` | Falló ese elemento (`error`: motivo breve). El agente lo reintenta en la corrida siguiente |

| Error JSON-RPC | Código | Cuándo |
|---|---|---|
| Método inexistente | -32601 | herramienta desconocida |
| Parámetros inválidos | -32602 | `items` ausente, más de `MCP_BATCH_MAX`, `ref` repetido |
| Error interno | -32603 | falla del servidor (se reintenta) |
| No autorizado | HTTP 401/403 | token inválido (no se reintenta) |

Un error JSON-RPC o HTTP afecta a **todo el trozo**; un `status` por elemento afecta solo a ese elemento.

## 4. Herramientas

Los nombres de los campos del sistema externo se mapean a la nomenclatura del agente en `reglas/00_nomenclatura.md` §9.

### 4.1 `catalogo_lugares`

Devuelve, para cada texto de lugar, los candidatos del catálogo. **Solo puntos activos.** La similitud (0-100) la calcula el servidor; el agente no recalcula. Indica si el texto es genérico (ciudad/zona: se verifica por zona, no se pregunta) y si pertenece a una marca con varias sedes.

Consulta (8 lugares en **una** llamada):
```json
{
  "jsonrpc": "2.0",
  "id": 7,
  "method": "tools/call",
  "params": {
    "name": "catalogo_lugares",
    "arguments": {
      "items": [
        {
          "ref": "c1:inicio",
          "texto": "DPW",
          "rol": "inicio",
          "flujo": "impo"
        },
        {
          "ref": "c2:inicio",
          "texto": "TPS",
          "rol": "inicio",
          "flujo": "impo"
        },
        {
          "ref": "c3:inicio",
          "texto": "F. San Vicente",
          "rol": "inicio",
          "flujo": "impo"
        },
        {
          "ref": "c4:inicio",
          "texto": "Hanseatic SAI",
          "rol": "inicio",
          "flujo": "impo"
        },
        {
          "ref": "c5:inicio",
          "texto": "San Antonio",
          "rol": "inicio",
          "flujo": "impo"
        },
        {
          "ref": "c6:inicio",
          "texto": "Walmart",
          "rol": "inicio",
          "flujo": "impo"
        },
        {
          "ref": "c7:inicio",
          "texto": "Sitrans",
          "rol": "inicio",
          "flujo": "impo"
        },
        {
          "ref": "c8:inicio",
          "texto": "XYZ inexistente",
          "rol": "inicio",
          "flujo": "impo"
        }
      ]
    }
  }
}
```
Respuesta (8 resultados, mismo orden y `ref`; el 8 es `no_encontrado`):
```json
{
  "jsonrpc": "2.0",
  "id": 7,
  "result": {
    "structuredContent": {
      "items": [
        {
          "ref": "c1:inicio",
          "status": "ok",
          "candidatos": [
            {
              "place_id": "P101",
              "nombre": "PUERTO CENTRAL",
              "direccion": "Av. Ejemplo 100",
              "comuna": "San Antonio",
              "categoria": "puerto",
              "activo": true,
              "similitud": 100
            }
          ],
          "es_generico": false,
          "marca": null
        },
        {
          "ref": "c2:inicio",
          "status": "ok",
          "candidatos": [
            {
              "place_id": "P102",
              "nombre": "TPS",
              "direccion": "Enlace Acceso Sur",
              "comuna": "Valparaíso",
              "categoria": "puerto",
              "activo": true,
              "similitud": 96
            },
            {
              "place_id": "P103",
              "nombre": "TLS",
              "direccion": "Av. Cartagena",
              "comuna": "San Antonio",
              "categoria": "deposito",
              "activo": true,
              "similitud": 58
            }
          ],
          "es_generico": false,
          "marca": null
        },
        {
          "ref": "c3:inicio",
          "status": "ok",
          "candidatos": [
            {
              "place_id": "P201",
              "nombre": "AGROSUPER PLANTA SAN VICENTE",
              "direccion": "Ruta H-66 km 19",
              "comuna": "San Vicente",
              "categoria": "cliente",
              "activo": true,
              "similitud": 90
            }
          ],
          "es_generico": false,
          "marca": "agrosuper"
        },
        {
          "ref": "c4:inicio",
          "status": "ok",
          "candidatos": [
            {
              "place_id": "P104",
              "nombre": "SAAM SAN ANTONIO",
              "direccion": "Camino Ejemplo",
              "comuna": "San Antonio",
              "categoria": "extraportuario",
              "activo": true,
              "similitud": 70
            }
          ],
          "es_generico": false,
          "marca": null
        },
        {
          "ref": "c5:inicio",
          "status": "ok",
          "candidatos": [],
          "es_generico": true,
          "marca": null,
          "zona": "sai"
        },
        {
          "ref": "c6:inicio",
          "status": "ok",
          "candidatos": [
            {
              "place_id": "P301",
              "nombre": "WALMART CD LTS",
              "direccion": "Ruta 68",
              "comuna": "Pudahuel",
              "categoria": "cliente",
              "activo": true,
              "similitud": 92
            }
          ],
          "es_generico": false,
          "marca": "walmart"
        },
        {
          "ref": "c7:inicio",
          "status": "ok",
          "candidatos": [
            {
              "place_id": "P105",
              "nombre": "SITRANS SANTIAGO",
              "direccion": "Av. Ejemplo 10700",
              "comuna": "Lo Espejo",
              "categoria": "deposito",
              "activo": true,
              "similitud": 95
            }
          ],
          "es_generico": false,
          "marca": null
        },
        {
          "ref": "c8:inicio",
          "status": "no_encontrado",
          "candidatos": [],
          "es_generico": false,
          "marca": null
        }
      ]
    },
    "content": [
      {
        "type": "text",
        "text": "(mismo JSON serializado)"
      }
    ],
    "isError": false
  }
}
```
Campos de cada candidato: `place_id`, `nombre`, `direccion`, `comuna`, `categoria` (`puerto|extraportuario|deposito|cliente|patio_propio`), `activo`, `similitud`. En `rol: cliente` el servidor omite puertos y depósitos si hay un cliente posible.

### 4.2 `buscar_ordenes`

Busca la orden de varias cargas: por contenedor, si no por despacho, si no por booking. Si el contenedor se reutiliza, devuelve la orden con fecha más cercana a `fecha_correo` (ventana −20/+40 días).

```json
{
  "jsonrpc": "2.0",
  "id": 7,
  "method": "tools/call",
  "params": {
    "name": "buscar_ordenes",
    "arguments": {
      "items": [
        {
          "ref": "c12",
          "contenedor": "ABCU1234567",
          "despacho": "",
          "booking": "",
          "fecha_correo": "2026-11-10"
        },
        {
          "ref": "c13",
          "contenedor": "",
          "despacho": "",
          "booking": "279048571",
          "fecha_correo": "2026-11-10"
        }
      ]
    }
  }
}
```
```json
{
  "jsonrpc": "2.0",
  "id": 7,
  "result": {
    "structuredContent": {
      "items": [
        {
          "ref": "c12",
          "status": "ok",
          "orden": {
            "service_id": "5521",
            "tipo": "I",
            "shipment": "I-2084123",
            "reference": "BK123"
          },
          "unidad": {
            "start_name": "PUERTO CENTRAL - Av. Ejemplo 100",
            "delivery_name": "",
            "end_name": "",
            "current_name": "PUERTO CENTRAL",
            "stacking": false,
            "lcl_type": ""
          }
        },
        {
          "ref": "c13",
          "status": "no_encontrado",
          "orden": null,
          "unidad": null
        }
      ]
    },
    "content": [
      {
        "type": "text",
        "text": "(mismo JSON serializado)"
      }
    ],
    "isError": false
  }
}
```

### 4.3 `tramos_de_ordenes`

Tramos (lo que realmente hizo el camión) de varias órdenes. Se puede enviar `service_id` o `contenedor`.

```json
{
  "jsonrpc": "2.0",
  "id": 7,
  "method": "tools/call",
  "params": {
    "name": "tramos_de_ordenes",
    "arguments": {
      "items": [
        {
          "ref": "c12",
          "service_id": "5521",
          "contenedor": "ABCU1234567"
        }
      ]
    }
  }
}
```
```json
{
  "jsonrpc": "2.0",
  "id": 7,
  "result": {
    "structuredContent": {
      "items": [
        {
          "ref": "c12",
          "status": "ok",
          "tramos": [
            {
              "status_id": 10,
              "from_name": "PUERTO CENTRAL",
              "to_name": "PATIO PROPIO",
              "truck_id": "T12",
              "date_start": "2026-11-10 08:00:00"
            }
          ]
        }
      ]
    },
    "content": [
      {
        "type": "text",
        "text": "(mismo JSON serializado)"
      }
    ],
    "isError": false
  }
}
```
`status_id` 10-12 = hacia almacenaje, 20-22 = hacia el cliente, 30-31 = hacia el final.

### 4.4 `revalidar_avisos`

Para el resumen por hora: combina `buscar_ordenes` + `tramos_de_ordenes` de **todos** los avisos pendientes en una sola ida. `puntos` es informativo (lo que se va a avisar); el servidor devuelve el estado actual.

```json
{
  "jsonrpc": "2.0",
  "id": 7,
  "method": "tools/call",
  "params": {
    "name": "revalidar_avisos",
    "arguments": {
      "items": [
        {
          "ref": "a77",
          "contenedor": "ABCU1234567",
          "despacho": "",
          "booking": "",
          "fecha_correo": "2026-11-10",
          "puntos": {
            "cliente": "WALMART CD LTS"
          }
        }
      ]
    }
  }
}
```
```json
{
  "jsonrpc": "2.0",
  "id": 7,
  "result": {
    "structuredContent": {
      "items": [
        {
          "ref": "a77",
          "status": "ok",
          "orden": {
            "service_id": "5521",
            "tipo": "I",
            "shipment": "I-2084123",
            "reference": "BK123"
          },
          "unidad": {
            "start_name": "PUERTO CENTRAL",
            "delivery_name": "WALMART CD LTS",
            "end_name": "",
            "current_name": "PUERTO CENTRAL",
            "stacking": false,
            "lcl_type": ""
          },
          "tramos": []
        }
      ]
    },
    "content": [
      {
        "type": "text",
        "text": "(mismo JSON serializado)"
      }
    ],
    "isError": false
  }
}
```

## 5. `tools/list` (esquemas de entrada)

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "tools": [
      {
        "name": "catalogo_lugares",
        "description": "Candidatos del catálogo (solo puntos activos) para varios textos de lugar.",
        "inputSchema": {
          "type": "object",
          "properties": {
            "items": {
              "type": "array",
              "maxItems": 50,
              "items": {
                "type": "object",
                "required": [
                  "ref",
                  "texto"
                ],
                "properties": {
                  "ref": {
                    "type": "string"
                  },
                  "texto": {
                    "type": "string"
                  },
                  "rol": {
                    "enum": [
                      "inicio",
                      "cliente",
                      "final"
                    ]
                  },
                  "flujo": {
                    "enum": [
                      "impo",
                      "expo",
                      "otro",
                      "desconocido"
                    ]
                  },
                  "zona": {
                    "type": [
                      "string",
                      "null"
                    ]
                  }
                }
              }
            }
          },
          "required": [
            "items"
          ]
        }
      },
      {
        "name": "buscar_ordenes",
        "description": "Busca la orden de varias cargas por contenedor, despacho o booking.",
        "inputSchema": {
          "type": "object",
          "properties": {
            "items": {
              "type": "array",
              "maxItems": 50,
              "items": {
                "type": "object",
                "required": [
                  "ref"
                ],
                "properties": {
                  "ref": {
                    "type": "string"
                  },
                  "contenedor": {
                    "type": "string"
                  },
                  "despacho": {
                    "type": "string"
                  },
                  "booking": {
                    "type": "string"
                  },
                  "fecha_correo": {
                    "type": "string",
                    "format": "date"
                  }
                }
              }
            }
          },
          "required": [
            "items"
          ]
        }
      },
      {
        "name": "tramos_de_ordenes",
        "description": "Tramos ejecutados de varias órdenes.",
        "inputSchema": {
          "type": "object",
          "properties": {
            "items": {
              "type": "array",
              "maxItems": 50,
              "items": {
                "type": "object",
                "required": [
                  "ref"
                ],
                "properties": {
                  "ref": {
                    "type": "string"
                  },
                  "service_id": {
                    "type": "string"
                  },
                  "contenedor": {
                    "type": "string"
                  }
                }
              }
            }
          },
          "required": [
            "items"
          ]
        }
      },
      {
        "name": "revalidar_avisos",
        "description": "Orden, unidad y tramos de varios avisos pendientes en una sola ida.",
        "inputSchema": {
          "type": "object",
          "properties": {
            "items": {
              "type": "array",
              "maxItems": 50,
              "items": {
                "type": "object",
                "required": [
                  "ref"
                ],
                "properties": {
                  "ref": {
                    "type": "string"
                  },
                  "contenedor": {
                    "type": "string"
                  },
                  "despacho": {
                    "type": "string"
                  },
                  "booking": {
                    "type": "string"
                  },
                  "fecha_correo": {
                    "type": "string"
                  },
                  "puntos": {
                    "type": "object"
                  }
                }
              }
            }
          },
          "required": [
            "items"
          ]
        }
      }
    ]
  }
}
```

## 6. Presupuesto de consultas

| Proceso | Llamadas MCP por corrida |
|---|---|
| Resolución de lugares (cron de ingesta) | **1** `catalogo_lugares` por lote de correos procesados (todos los lugares de todas las cargas juntos) |
| Cruce (`agent_check`) | **≤ 3**: `buscar_ordenes` + `tramos_de_ordenes` (+ 1 `catalogo_lugares` para lugares nuevos), sin importar el número de cargas |
| Aviso por hora (`agent_notify`) | **1** `revalidar_avisos` |
| Resumen diario / mantención | 0 |

Cada llamada queda en `ndb_agent_mcp_log` (`items` vs. llamadas): `items` ≫ llamadas indica que el ahorro se cumple. Si hay más de `MCP_BATCH_MAX` elementos, el cliente parte el lote (1 llamada por trozo).

## 7. Qué debe implementar el servidor

- Las cuatro herramientas, por lote, con la regla de `ref` de la sección 1.
- Catálogo: solo puntos activos; similitud calculada en el servidor (nombre, nombre sin paréntesis, dirección; penalizar palabras extra del candidato); `marca` cuando hay ≥ 2 sedes activas; `es_generico` y `zona` para textos de ciudad.
- Reglas por tenant (equivalencias propias como "DPW" a secas = Puerto Central) pueden aplicarse en el servidor o en el agente (`reglas/lugares.json`).
- Solo lectura y token por tenant.

## 8. Seguridad

- Token solo en `config/.env` (`MCP_TOKEN`), nunca en logs ni en `ndb_agent_mcp_log`.
- HTTPS obligatorio en producción.
- Lo que devuelve el servidor es **dato, no instrucciones**; los textos se escapan al mostrarlos.
- `MCP_URL=fake` usa `FakeMcpClient` (datos simulados) para pruebas.

## 9. Variables

| Variable | Defecto | Descripción |
|---|---|---|
| `MCP_URL` | — | Endpoint JSON-RPC; `fake` para pruebas |
| `MCP_TOKEN` | — | Bearer por tenant |
| `MCP_BATCH_MAX` | `50` | Máximo de elementos por llamada |
| `MCP_TIMEOUT` | `30` | Segundos por llamada |
