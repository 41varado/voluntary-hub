# Medición 3 · Consulta del portal con y sin índice

Responsable: Luis Aguilar Monge (Persona 2). Script: [scripts/medir_indice.php](../../scripts/medir_indice.php).

## Qué se mide

La consulta con la que el portal muestra una página de actividades: el mismo filtro, orden y límite
que usa la aplicación (`ActividadRepositorio::filtroPortal()`, ordenada por fecha, 9 por página).
Para cada plan se obtiene `explain("executionStats")` y se mide el tiempo de 20 ejecuciones.

Para que la diferencia se note, el script agrega actividades sintéticas (20 000 por defecto) de una
organización temporal y las borra al terminar. No cambia los datos de prueba ni borra índices: cada
plan se fuerza con `hint`.

| Plan | Cómo se fuerza | Qué hace |
|---|---|---|
| Sin índice | `hint: {$natural: 1}` | Recorre toda la colección (`COLLSCAN`) y ordena en memoria |
| `portal_por_fecha` | `hint: "portal_por_fecha"` | Índice `{estado, fechaInicio}` |
| `portal_con_filtros` | `hint: "portal_con_filtros"` | Índice `{estado, categoria, provincia, fechaInicio}` |
| Automático | sin `hint` | El plan que elige MongoDB, que es el mismo que usa la aplicación |

Casos: sin filtros; solo categoría (*Ambiente y reforestación*); y categoría y provincia (*Limpieza de
playas y ríos* en *Puntarenas*).

## Cómo correrla

En el Codespace, con el esquema creado (`php scripts/crear_esquema.php`):

```bash
docker compose exec app php scripts/medir_indice.php --volumen=20000 --repeticiones=3
```

Opciones:

- `--volumen`: actividades sintéticas que se agregan (20 000 por defecto; 0 para medir solo los datos
  cargados).
- `--repeticiones`: 3 por defecto.
- `--ejecuciones`: veces que se ejecuta cada plan en cada repetición (20 por defecto).
- `--semilla`: 2026 por defecto; con la misma semilla se generan los mismos datos.

Si una corrida se interrumpe, la siguiente borra las actividades sintéticas que hayan quedado. El
script guarda todas las filas en `docs/mediciones/indice_AAAAMMDD_HHMMSS.csv`.

## Resultados

_Pendiente de correr._

- **Entorno:** _(Codespaces, tipo de máquina)_
- **MongoDB:** _(versión, la imprime el script)_
- **Actividades en la colección:** _(lo imprime el script)_
- **CSV:** _(nombre del archivo)_

| Caso | Plan | Claves examinadas | Documentos examinados | Devueltos | Mediana (ms) | Etapas del plan |
|---|---|---|---|---|---|---|
| Sin filtros | Sin índice | | | | | |
| Sin filtros | `portal_por_fecha` | | | | | |
| Sin filtros | `portal_con_filtros` | | | | | |
| Sin filtros | Automático | | | | | |
| Solo categoría | Sin índice | | | | | |
| Solo categoría | `portal_por_fecha` | | | | | |
| Solo categoría | `portal_con_filtros` | | | | | |
| Solo categoría | Automático | | | | | |
| Categoría y provincia | Sin índice | | | | | |
| Categoría y provincia | `portal_por_fecha` | | | | | |
| Categoría y provincia | `portal_con_filtros` | | | | | |
| Categoría y provincia | Automático | | | | | |

La mediana es la de 3 repeticiones; claves y documentos examinados son iguales en todas.

## Qué revisar al interpretar

Son hipótesis para confirmar o descartar con los resultados:

- Sin índice, los documentos examinados deberían ser toda la colección en los tres casos.
- Categoría y provincia es el caso para el que existe `portal_con_filtros`. Con ese índice, los
  documentos examinados deberían acercarse a los 9 que se devuelven, sin `SORT` en memoria.
- Con solo categoría, la provincia queda libre en medio del índice, así que `portal_con_filtros` no
  da el orden por fecha. Hay que ver si aparece un `SORT` en sus etapas y si el plan automático
  prefiere `portal_por_fecha`.
- Sin filtros, `portal_por_fecha` debería ser el mejor plan.
- Si algún índice no lo usa ninguna consulta, eso es una decisión para `docs/decisiones.md`.

## Interpretación

_Pendiente: se escribe con los resultados._
