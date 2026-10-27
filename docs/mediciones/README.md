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
latencia mediana y el percentil 95. Al terminar borra la actividad de prueba. Detalle y resultados
en [cupos.md](cupos.md).

**Resultado esperado:** sobrecupo 0 en los dos modos; con Redis, las solicitudes rechazadas no
llegan a MongoDB y el tiempo total baja.

## 2. Listado de actividades con y sin caché

```bash
php scripts/medir_listado.php --consultas=300 --repeticiones=3
```

Pide el listado del portal 300 veces por repetición, repartidas entre cinco filtros típicos, primero
con la caché de Redis y después solo con MongoDB (`REDIS_DESHABILITADO=true`). Reporta cuántas
consultas salieron de la caché, el tiempo total, la latencia mediana y el percentil 95. Detalle y
resultados en [listado.md](listado.md).

**Resultado esperado:** con caché, 295 de 300 consultas salen de Redis y la latencia mediana baja.

## 3. Consulta del portal con y sin índice

```bash
php scripts/medir_indice.php --volumen=20000 --repeticiones=3
```

Agrega 20 000 actividades sintéticas y compara con `explain("executionStats")` la consulta del
portal sin índice, con `portal_por_fecha`, con `portal_con_filtros` y con el plan que elige MongoDB
(cada plan se fuerza con `hint`, D22). Al terminar borra las actividades sintéticas. Detalle y
resultados en [indice.md](indice.md).

**Resultado esperado:** sin índice se examina toda la colección; con `portal_con_filtros`, al
filtrar por categoría y provincia, los documentos examinados se acercan a los 9 que se devuelven.
