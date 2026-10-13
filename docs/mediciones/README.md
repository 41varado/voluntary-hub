# Mediciones

Cada script de medición guarda aquí un CSV con todas las repeticiones. El curso pide al menos
3 mediciones, cada una con 3 ejecuciones o más, reportando la mediana.

## 1. Reservas simultáneas con y sin Redis

```bash
php scripts/medir_cupos.php --solicitudes=500 --cupos=50 --procesos=20 --repeticiones=3
```

Crea una actividad temporal de 50 cupos y lanza 500 reservas desde 20 procesos a la vez, primero
con Redis + MongoDB y después solo con MongoDB (`REDIS_DESHABILITADO=true` en los procesos).
Verifica en MongoDB cuántas inscripciones quedaron y reporta el sobrecupo, el tiempo total, la
latencia mediana y el percentil 95. Al terminar borra la actividad de prueba.

**Resultado esperado:** sobrecupo 0 en los dos modos; con Redis, las solicitudes rechazadas no
llegan a MongoDB y el tiempo total baja.

## 2. Listado de actividades con y sin caché (seguimiento 3)

Pendiente: medir el tiempo de `/actividades` con la caché de Redis y con `REDIS_DESHABILITADO=true`.

## 3. Consulta del portal con y sin índice (seguimiento 3)

Pendiente: `explain("executionStats")` de la consulta del portal con el índice
`portal_con_filtros` y sin él, sobre un volumen mayor (`cargar_datos.php --voluntarios=…`).
