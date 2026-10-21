# Medición 1 · Reservas simultáneas con y sin Redis

Responsable: Manuel Mora Sandi (Persona 3). Script: [scripts/medir_cupos.php](../../scripts/medir_cupos.php).

## Qué se mide

Muchas personas intentan reservar a la vez en una actividad con cupo limitado. El script crea una
actividad temporal de 50 cupos y lanza 500 reservas desde 20 procesos que arrancan al mismo tiempo,
primero con Redis + MongoDB y después solo con MongoDB (`REDIS_DESHABILITADO=true` en los procesos).
Al final verifica en MongoDB cuántas inscripciones quedaron y borra la actividad.

Reporta, por repetición y como mediana de las repeticiones:

- **Aceptadas y en MongoDB:** reservas aceptadas e inscripciones que quedaron guardadas.
- **Sobrecupo:** inscripciones de más sobre los 50 cupos. Tiene que ser 0 en los dos modos.
- **Tiempo total:** desde que arrancan los procesos hasta que termina el último.
- **Latencia mediana y p95:** de cada solicitud de reserva.

## Cómo correrla

En el Codespace, con el esquema creado:

```bash
docker compose exec app php scripts/medir_cupos.php --solicitudes=500 --cupos=50 --procesos=20 --repeticiones=3
```

Correrla con la versión final del servicio de cupos, porque la corrección del contador y la
bitácora de eventos agregan pasos a la reserva. El script guarda todas las filas en
`docs/mediciones/cupos_AAAAMMDD_HHMMSS.csv`.

## Resultados

_Pendiente de correr._

- **Entorno:** _(Codespaces, tipo de máquina)_
- **CSV:** _(nombre del archivo)_

| Modo | Aceptadas | En MongoDB | Sobrecupo | Tiempo total (ms) | Latencia mediana (ms) | p95 (ms) |
|---|---|---|---|---|---|---|
| Redis + MongoDB | | | | | | |
| Solo MongoDB | | | | | | |

Mediana de 3 repeticiones de 500 solicitudes sobre 50 cupos.

## Qué revisar al interpretar

- **Sobrecupo 0 en los dos modos.** Sin Redis, es la evidencia de que MongoDB solo impide el
  sobrecupo (prueba EC-03).
- **Solicitudes rechazadas:** con Redis, las 450 que sobran se rechazan en Redis sin llegar a
  MongoDB; sin Redis, todas hacen la operación en MongoDB. Eso es lo que debería verse en el tiempo
  total y en el p95.
- **Latencia de una reserva aceptada:** con Redis tiene más pasos (candado, script de cupos y
  bitácora), así que puede no ser menor que sin Redis. La ganancia está en descargar a MongoDB bajo
  carga.

## Interpretación

_Pendiente: se escribe con los resultados._
