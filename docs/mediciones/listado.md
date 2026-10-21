# Medición 2 · Listado con y sin caché

Responsable: Luis Aguilar Monge (Persona 2). Script: [scripts/medir_listado.php](../../scripts/medir_listado.php).

## Qué se mide

El listado del portal (`/actividades`) con la caché de Redis y sin ella. Se mide la función que arma
el listado, `ActividadRepositorio::listarPublicadas()`, que es lo único que cambia entre los dos
modos: dibujar la página cuesta lo mismo en ambos.

- **Con caché:** cada repetición empieza con la caché vacía. La primera consulta de cada filtro va a
  MongoDB (un conteo y una búsqueda) y guarda el resultado en Redis; las siguientes salen de Redis.
- **Sin caché:** el script desactiva Redis (igual que `REDIS_DESHABILITADO=true`) y todas las
  consultas van a MongoDB.

Las consultas se reparten entre cinco filtros típicos del portal: sin filtros, por categoría, por
provincia, por categoría y provincia, y solo con cupo.

## Cómo correrla

En el Codespace, con los datos de prueba cargados:

```bash
docker compose exec app php scripts/medir_listado.php --consultas=300 --repeticiones=3
```

Opciones: `--consultas` por repetición (300 por defecto), `--repeticiones` (3 por defecto) y
`--modo=redis|mongodb|ambos`. El script imprime cada repetición y la mediana de las repeticiones, y
guarda todas las filas en `docs/mediciones/listado_AAAAMMDD_HHMMSS.csv`.

## Resultados

_Pendiente de correr._

- **Entorno:** _(Codespaces, tipo de máquina)_
- **Actividades publicadas:** _(lo imprime el script)_
- **CSV:** _(nombre del archivo)_

| Modo | Consultas desde la caché | Tiempo total (ms) | Latencia mediana (ms) | p95 (ms) |
|---|---|---|---|---|
| Con caché (Redis + MongoDB) | | | | |
| Sin caché (solo MongoDB) | | | | |

Mediana de 3 repeticiones de 300 consultas cada una.

## Qué revisar al interpretar

- Con caché, 295 de 300 consultas deberían salir de Redis: solo la primera de cada uno de los 5
  filtros va a MongoDB.
- Las columnas `mediana_cache_ms` y `mediana_mongodb_ms` del CSV comparan una consulta resuelta en
  Redis con una resuelta en MongoDB. La primera consulta de cada filtro cuesta un poco más con caché
  que sin ella, porque además guarda el resultado en Redis.
- Al final, el script imprime cuántas veces menor es la latencia mediana con caché.

## Interpretación

_Pendiente: se escribe con los resultados._
