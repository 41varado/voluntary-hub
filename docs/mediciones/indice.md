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

- **Entorno:** GitHub Codespaces, con `docker compose exec app`, el 26 de octubre de 2026.
- **MongoDB:** 7, la imagen `mongo:7` de `docker-compose.yml`. El script imprime la versión exacta,
  pero no queda en el CSV.
- **Actividades en la colección:** 20 042: las 42 de los datos de prueba y 20 000 sintéticas.
- **CSV:** [indice_20261026_190240.csv](indice_20261026_190240.csv)

| Caso | Plan | Claves examinadas | Documentos examinados | Devueltos | Mediana (ms) | Etapas del plan |
|---|---|---|---|---|---|---|
| Sin filtros | Sin índice | 0 | 20 042 | 9 | 16,624 | SORT → COLLSCAN |
| Sin filtros | `portal_por_fecha` | 9 | 9 | 9 | 0,348 | LIMIT → FETCH → IXSCAN portal_por_fecha |
| Sin filtros | `portal_con_filtros` | 10 712 | 9 | 9 | 14,721 | FETCH → SORT → IXSCAN portal_con_filtros |
| Sin filtros | Automático | 9 | 9 | 9 | 0,341 | LIMIT → FETCH → IXSCAN portal_por_fecha |
| Solo categoría | Sin índice | 0 | 20 042 | 9 | 12,825 | SORT → COLLSCAN |
| Solo categoría | `portal_por_fecha` | 54 | 54 | 9 | 0,450 | LIMIT → FETCH → IXSCAN portal_por_fecha |
| Solo categoría | `portal_con_filtros` | 1 822 | 9 | 9 | 2,658 | FETCH → SORT → IXSCAN portal_con_filtros |
| Solo categoría | Automático | 54 | 54 | 9 | 0,456 | LIMIT → FETCH → IXSCAN portal_por_fecha |
| Categoría y provincia | Sin índice | 0 | 20 042 | 9 | 12,526 | SORT → COLLSCAN |
| Categoría y provincia | `portal_por_fecha` | 372 | 372 | 9 | 1,064 | LIMIT → FETCH → IXSCAN portal_por_fecha |
| Categoría y provincia | `portal_con_filtros` | 9 | 9 | 9 | 0,381 | LIMIT → FETCH → IXSCAN portal_con_filtros |
| Categoría y provincia | Automático | 9 | 9 | 9 | 0,375 | LIMIT → FETCH → IXSCAN portal_con_filtros |

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

Las hipótesis de arriba se confirmaron:

- **Sin índice se examina toda la colección, 20 042 documentos, en los tres casos**, con un `SORT` en
  memoria: cada consulta tarda entre 12,5 y 16,6 ms.
- **Categoría y provincia:** con `portal_con_filtros` se examinan 9 claves y 9 documentos, justo los 9
  que se devuelven, sin `SORT` en memoria. Tarda 0,381 ms, unas 33 veces menos que sin índice
  (12,526 ms), y es el plan que MongoDB elige solo.
- **Solo categoría:** `portal_con_filtros` no da el orden por fecha, porque la provincia queda libre en
  medio del índice: aparece un `SORT` y recorre 1 822 claves (2,658 ms). MongoDB prefiere
  `portal_por_fecha`, que examina 54 documentos y tarda 0,456 ms.
- **Sin filtros:** `portal_por_fecha` es el mejor plan (9 claves, 0,348 ms) y es el que elige MongoDB.
  `portal_con_filtros` recorre 10 712 claves y ordena en memoria (14,721 ms), casi lo mismo que sin
  índice.
- **Los dos índices se usan:** cada uno es el mejor plan en algún caso, así que se conservan los dos.
