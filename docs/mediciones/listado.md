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

- **Entorno:** GitHub Codespaces, con `docker compose exec app`, el 26 de octubre de 2026.
- **Actividades publicadas:** las de los datos de prueba de `cargar_datos.php`. El script imprime
  cuántas son, pero ese número no queda en el CSV.
- **CSV:** [listado_20261026_195702.csv](listado_20261026_195702.csv)

| Modo | Consultas desde la caché | Tiempo total (ms) | Latencia mediana (ms) | p95 (ms) |
|---|---|---|---|---|
| Con caché (Redis + MongoDB) | 295 de 300 | 73,7 | 0,160 | 0,578 |
| Sin caché (solo MongoDB) | 0 de 300 | 521,4 | 0,958 | 4,676 |

Mediana de 3 repeticiones de 300 consultas cada una.

## Qué revisar al interpretar

- Con caché, 295 de 300 consultas deberían salir de Redis: solo la primera de cada uno de los 5
  filtros va a MongoDB.
- Las columnas `mediana_cache_ms` y `mediana_mongodb_ms` del CSV comparan una consulta resuelta en
  Redis con una resuelta en MongoDB. La primera consulta de cada filtro cuesta un poco más con caché
  que sin ella, porque además guarda el resultado en Redis.
- Al final, el script imprime cuántas veces menor es la latencia mediana con caché.

## Interpretación

- **295 de 300 consultas salieron de Redis en cada repetición:** solo la primera de cada uno de los
  5 filtros fue a MongoDB, como se esperaba.
- **La latencia mediana baja de 0,958 a 0,160 ms, unas 6 veces menos,** y las 300 consultas terminan
  en 73,7 ms en lugar de 521,4 ms. El p95 baja de 4,676 a 0,578 ms.
- **En el modo con caché, una consulta resuelta en Redis tarda 0,159 ms (mediana) y una que va a
  MongoDB, 2,128 ms.** La primera de cada filtro cuesta más que sin caché (0,958 ms) porque además
  guarda el resultado en Redis; ese costo se paga una vez por filtro mientras dura la caché (60 s, o
  hasta el siguiente cambio).
- Con los datos de prueba, MongoDB ya responde en menos de 1 ms. La ganancia de la caché es que el
  listado, la página más visitada, casi no le pide nada a MongoDB.
