# Mapa de claves de Redis

Todas las claves llevan el prefijo de `REDIS_PREFIX`, que en el Redis compartido del curso es
obligatoriamente `grupoX:` (X = número del grupo). Ejemplo completo:
`grupo3:voluntacr:cupos:6acd821011b86f1bd209b2da`.

Reglas del equipo:

- Nunca `FLUSHALL` ni `FLUSHDB`. Para borrar las claves propias se usa `SCAN` con el prefijo
  (`scripts/cargar_datos.php --limpiar`).
- Nunca `KEYS *`: en un Redis compartido bloquea a los demás grupos. Se usa `SCAN`.
- Toda clave tiene vencimiento (TTL), salvo `cache:actividades:version`, que es un número pequeño.
- Ningún dato importante vive **solo** en Redis: todo se puede reconstruir desde MongoDB, salvo las
  visitas y la bitácora de eventos, que son datos de apoyo y se pueden perder (D18).
- Los comandos que no tiene el cliente integrado (`src/Nucleo/RedisPolyfill.php`, el que se usa en un
  servidor sin la extensión phpredis), como los de hash, sorted set y stream, se ejecutan con `EVAL`.
  Así funcionan igual con los dos clientes.

## Claves en uso

| Clave (sin prefijo) | Estructura | TTL | Quién la usa | Para qué |
|---|---|---|---|---|
| `cupos:{actividadId}` | string (entero) | 1 h | Inscripciones | Contador de cupos libres. Un script Lua hace `GET` y `DECR` solo si es mayor que 0. Se borra cuando un cupo se devuelve, y se recarga desde MongoDB. |
| `candado:reserva:{actividadId}:{usuarioId}` | string | 5 s | Inscripciones | `SET NX`: evita que un doble clic procese dos reservas a la vez. |
| `candado:aviso:{actividadId}` | string | 10 s | Notificaciones | `SET NX`: un mensaje de la organización por actividad cada 10 s, contra el doble clic. No se borra al terminar, así un segundo envío que llega justo después también se rechaza. Si Redis no responde, no hay candado. |
| `liberando:{actividadId}` | string | 15 s | Inscripciones | Cuando el contador dice que la actividad está llena, permite confirmarlo en MongoDB como máximo una vez cada 15 s: se liberan las reservas vencidas y se recarga el contador, por si quedó desfasado (por ejemplo, después de una caída de Redis). |
| `eventos` | stream | 30 días desde el último evento | Inscripciones | Bitácora de reservas, confirmaciones, cancelaciones y vencimientos: tipo, actividad y, en las reservas, el motor y los ms. `XADD` con `MAXLEN ~ 10000` y `EXPIRE` en un solo `EVAL`. `/estado` muestra los últimos 10. Es un registro de apoyo: el estado real de cada inscripción está en MongoDB. |
| `cache:actividades:version` | string (entero) | sin TTL | Actividades | Versión del listado. `INCR` invalida toda la caché del listado de una vez. |
| `cache:actividades:v{version}:{md5(filtros)}` | string (JSON) | 60 s (`CACHE_TTL_SEG`) | Actividades | Una página del listado con sus filtros. |
| `respaldo:actividades:{md5(filtros)}` | string (JSON) | 24 h | Actividades | Última versión conocida del listado; solo se lee si MongoDB no responde. |
| `actividad:{actividadId}:visitas` | hash | 90 días desde la última visita | Actividades | Visitas al detalle, un campo por día (`2026-10-27` → visitas); se cuenta una por navegador y por día. El panel del coordinador muestra el total y las de hoy. `HINCRBY` y `HGETALL` con `EVAL`. |
| `intentos:login:{md5(correo)}` | string (entero) | 15 min | Usuarios | Intentos fallidos; al llegar a 5 se bloquea ese correo 15 minutos. |
| `ranking:horas` | sorted set | 1 h | Usuarios | Horas acumuladas por voluntario (miembro: id; puntaje: horas). Guardar la asistencia suma o resta con `ZINCRBY`; al vencer, la siguiente consulta lo reconstruye desde MongoDB con una agregación (D20). "Mis horas" muestra el puesto y los 5 primeros. |
| `hola` | string | 30 s | Estado | Prueba de escritura y lectura de la página `/estado`. |

## Estructuras

El curso pide al menos 3 estructuras distintas. Se usan cuatro: *string* (cupos, candados, caché del
listado, intentos de inicio de sesión), *hash* (visitas), *sorted set* (ranking) y *stream* (eventos).

## Comportamiento si Redis no responde

Cada operación pasa por `RedisSeguro::usar()`, que devuelve un valor de respaldo cuando Redis no
está. Ver la sección "¿Qué ocurre si Redis se cae?" en [arquitectura](arquitectura/README.md).

## Verificación final

Revisión del seguimiento 4, el 28 de octubre de 2026.

**Comandos prohibidos en el Redis compartido.** Búsqueda en todo el código:

```bash
git grep -niE "flushall|flushdb" -- src scripts public
git grep -niE "(->keys\(|'KEYS'|\"KEYS\"|redis\.call\('keys')" -- src scripts public
```

- `FLUSHALL` y `FLUSHDB` aparecen solo en dos comentarios que dicen que no se usan
  (`src/Nucleo/RedisSeguro.php` y `scripts/cargar_datos.php`).
- `KEYS` no aparece. Las claves del grupo se recorren con `SCAN` y el prefijo en dos lugares:
  `cargar_datos.php --limpiar`, que borra solo las claves del grupo, y `/estado`, que las cuenta.
- Fuera de `EVAL`, la aplicación solo manda comandos de string (`GET`, `SET` con `NX` y `EX`,
  `SETEX`, `INCR`, `EXPIRE`, `DEL`), `SCAN`, `PING`, `INFO`, `AUTH` y `SELECT`. Dentro de los scripts
  Lua: `DECR`, `GET`, `DEL`, `EXISTS`, `EXPIRE`, `HINCRBY`, `HGETALL`, `ZADD`, `ZINCRBY`, `ZREM`,
  `ZSCORE`, `ZCARD`, `ZCOUNT`, `ZREVRANGE`, `XADD`, `XLEN` y `XREVRANGE`. Las pruebas automatizadas
  agregan `TTL`, para revisar el vencimiento del stream (EC-06).

**Ver las claves del grupo sin `KEYS *`**, por ejemplo en el servidor del curso:

```bash
redis-cli --scan --pattern 'grupo3:voluntacr:*'          # todas las del grupo
redis-cli --scan --pattern 'grupo3:voluntacr:cupos:*'    # una sola familia
```

`/estado` muestra cuántas son. En el servidor, el 28 de octubre, eran 18.

**Claves reales.** En la instalación local desde cero del 28 de octubre (Redis 7.2.16, prefijo
`grupoX:voluntacr:`) se leyeron con `redis-cli --scan`, `TYPE` y `TTL` en tres momentos: después de
una reserva, después de un aviso de la organización y al final de una sesión con un inicio de sesión
fallido y una visita a `/estado`.

| Clave (sin prefijo) | Tipo | TTL leído | TTL del mapa |
|---|---|---|---|
| `cupos:{actividadId}` | string | 3591 a 3599 s | 1 h |
| `candado:aviso:{actividadId}` | string | 5 a 10 s | 10 s |
| `cache:actividades:version` | string | sin TTL (−1) | sin TTL |
| `cache:actividades:v{version}:{md5}` | string | 50 a 58 s | 60 s |
| `respaldo:actividades:{md5}` | string | 86 390 a 86 398 s | 24 h |
| `actividad:{actividadId}:visitas` | hash | 7 775 989 a 7 775 999 s | 90 días |
| `intentos:login:{md5}` | string | 900 s | 15 min |
| `ranking:horas` | zset | 3596 s | 1 h |
| `eventos` | stream | 2 591 993 a 2 592 000 s | 30 días |
| `hola` | string | 30 s | 30 s |

Todas coinciden con el mapa. Las dos que no aparecen también existen, pero duran muy poco:
`candado:reserva:{actividadId}:{usuarioId}` se borra apenas termina la reserva, y
`liberando:{actividadId}` solo se crea cuando una actividad llena recibe otra reserva; es la que
corrige el contador en EC-04, que pasó en esa misma instalación.
