# Mapa de claves de Redis

Todas las claves llevan el prefijo de `REDIS_PREFIX`, que en el Redis compartido del curso es
obligatoriamente `grupoX:` (X = número del grupo). Ejemplo completo:
`grupo3:voluntacr:cupos:6acd821011b86f1bd209b2da`.

Reglas del equipo:

- Nunca `FLUSHALL` ni `FLUSHDB`. Para borrar las claves propias se usa `SCAN` con el prefijo
  (`scripts/cargar_datos.php --limpiar`).
- Nunca `KEYS *`: en un Redis compartido bloquea a los demás grupos. Se usa `SCAN`.
- Toda clave tiene vencimiento (TTL), salvo `cache:actividades:version`, que es un número pequeño.
- Ningún dato vive **solo** en Redis: todo se puede reconstruir desde MongoDB.
- Los comandos que no tiene el cliente integrado (`src/Nucleo/RedisPolyfill.php`, el que se usa en un
  servidor sin la extensión phpredis), como los de hash, sorted set y stream, se ejecutan con `EVAL`.
  Así funcionan igual con los dos clientes.

## Claves en uso (v0.3)

| Clave (sin prefijo) | Estructura | TTL | Quién la usa | Para qué |
|---|---|---|---|---|
| `cupos:{actividadId}` | string (entero) | 1 h | Inscripciones | Contador de cupos libres. Un script Lua hace `GET` y `DECR` solo si es mayor que 0. Se borra cuando un cupo se devuelve, y se recarga desde MongoDB. |
| `candado:reserva:{actividadId}:{usuarioId}` | string | 5 s | Inscripciones | `SET NX`: evita que un doble clic procese dos reservas a la vez. |
| `liberando:{actividadId}` | string | 15 s | Inscripciones | Cuando el contador dice que la actividad está llena, permite confirmarlo en MongoDB como máximo una vez cada 15 s: se liberan las reservas vencidas y se recarga el contador, por si quedó desfasado (por ejemplo, después de una caída de Redis). |
| `eventos` | stream | 30 días desde el último evento | Inscripciones | Bitácora de reservas, confirmaciones, cancelaciones y vencimientos: tipo, actividad y, en las reservas, el motor y los ms. `XADD` con `MAXLEN ~ 10000` y `EXPIRE` en un solo `EVAL`. `/estado` muestra los últimos 10. Es un registro de apoyo: el estado real de cada inscripción está en MongoDB. |
| `cache:actividades:version` | string (entero) | sin TTL | Actividades | Versión del listado. `INCR` invalida toda la caché del listado de una vez. |
| `cache:actividades:v{version}:{md5(filtros)}` | string (JSON) | 60 s (`CACHE_TTL_SEG`) | Actividades | Una página del listado con sus filtros. |
| `respaldo:actividades:{md5(filtros)}` | string (JSON) | 24 h | Actividades | Última versión conocida del listado; solo se lee si MongoDB no responde. |
| `intentos:login:{md5(correo)}` | string (entero) | 15 min | Usuarios | Intentos fallidos; al llegar a 5 se bloquea ese correo 15 minutos. |
| `hola` | string | 30 s | Estado | Prueba de escritura y lectura de la página `/estado`. |

## Planeadas para el seguimiento 3

El curso pide al menos 3 estructuras distintas. Hoy se usan *string* y *stream*; se agregan:

| Clave | Estructura | Para qué |
|---|---|---|
| `ranking:horas` | sorted set | Voluntarios con más horas (`ZINCRBY` al registrar asistencia). Se reconstruye desde MongoDB con una agregación. |
| `actividad:{id}:visitas` | hash | Visitas y reservas por actividad y día, para el panel del coordinador. |

## Comportamiento si Redis no responde

Cada operación pasa por `RedisSeguro::usar()`, que devuelve un valor de respaldo cuando Redis no
está. Ver la sección "¿Qué ocurre si Redis se cae?" en [arquitectura](arquitectura/README.md).
