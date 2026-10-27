# Pruebas · Error de conexión

Responsable: Manuel Mora Sandi (Persona 3). Formato y reglas en [README.md](README.md). Qué hace la
aplicación en cada caso está en [arquitectura](../arquitectura/README.md#qué-ocurre-si-redis-se-cae).

| Prueba | Qué comprueba | Estado |
|---|---|---|
| EC-01 | `/estado` en los tres modos: normal, respaldo (sin Redis) y crítico (sin MongoDB) | Paso 1: Pasa. Pasos 2 a 6: en vivo con el profesor |
| EC-02 | Con Redis caído, el portal, las reservas y el inicio de sesión siguen funcionando | En vivo con el profesor |
| EC-03 | Sin Redis no hay sobrecupo, ni con 500 solicitudes simultáneas | Pasa |
| EC-04 | Cuando Redis vuelve, un contador de cupos desfasado se corrige solo | Pasa |
| EC-05 | Con MongoDB caído, el listado sale de la caché y lo demás muestra una página 503 | En vivo con el profesor |
| EC-06 | La bitácora de eventos con Redis caído | Pasos 1 y 4: Pasa. Pasos 2 y 3: en vivo con el profesor |

Resultados del 26 de octubre de 2026 en el servidor del curso (https://grupo3.crnube.net/proyecto),
con las pruebas automatizadas de `scripts/pruebas/correr.php` ([cómo se corren](README.md#pruebas-automatizadas)).
El informe completo está en [resultados/pruebas_20261026_201329.txt](resultados/pruebas_20261026_201329.txt).
El script sigue los pasos por HTTP, con una sesión por cuenta, y al título de cada actividad de
prueba le agrega la fecha y la hora de la corrida («Prueba CN-01 1026-2013»).

Los pasos que necesitan pausar o detener un motor no se pueden automatizar en el servidor del curso:
se hicieron en vivo con el profesor y sus resultados no quedaron registrados aquí.

## Preparación

1. Datos de prueba cargados y `APP_MODO_DEMO=true` (viene así en `.env.example`).
2. Dos ventanas del navegador:
   - **Ventana A:** `coordinador1@voluntacr.test`, contraseña `Coordina2026`.
   - **Ventana B (privada):** `voluntario1@voluntacr.test`, contraseña `Voluntario2026`.
3. Una terminal en la carpeta del proyecto.

### Cómo simular las caídas

En Codespaces o Docker:

| Qué | Para que falle | Para que vuelva |
|---|---|---|
| Redis no responde | `docker compose pause redis` | `docker compose unpause redis` |
| MongoDB no responde | `docker compose stop mongo` | `docker compose start mongo` |

`pause` congela Redis sin apagarlo: la aplicación espera su tiempo límite (0,5 s) y pasa a modo
respaldo, igual que cuando el servidor de Redis no contesta.

En el servidor del curso no se pueden apagar los motores. Se cambia una línea del `.env` y se
deshace al terminar:

- **Redis:** `REDIS_PORT=1`, un puerto donde nadie escucha.
- **MongoDB:** `MONGO_URI=mongodb://127.0.0.1:1`.

Para leer claves de Redis en Codespaces: `docker compose exec redis redis-cli`. Las claves llevan el
prefijo de `REDIS_PREFIX`; aquí se usa el de `.env.example`, `grupoX:voluntacr:`.

### EC-01 · `/estado` en los tres modos

- **Objetivo:** comprobar que `/estado` dice qué motor no responde y en qué modo funciona la
  aplicación.
- **Precondiciones:** las de la preparación.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Con todo funcionando, abrir `/estado`. | "Funcionamiento normal"; MongoDB y Redis dicen "Conectado". | Igual. |
| 2 | `docker compose pause redis` y recargar `/estado`. | Tarda medio segundo más. "Modo respaldo"; Redis dice "Sin conexión" con el motivo "Redis no responde (…). Modo respaldo con MongoDB durante 30 s."; MongoDB sigue "Conectado". | En vivo con el profesor; sin registro. |
| 3 | Recargar otra vez. | Responde enseguida, con el motivo "Redis falló hace poco; se volverá a intentar en N s.". | En vivo con el profesor; sin registro. |
| 4 | `docker compose unpause redis`, esperar 30 s y recargar. | "Funcionamiento normal". | En vivo con el profesor; sin registro. |
| 5 | `docker compose stop mongo` y recargar `/estado`. | Tarda unos segundos. "MongoDB no responde"; MongoDB dice "Sin conexión". `curl -s -o /dev/null -w "%{http_code}\n" localhost:8080/estado.json` muestra `503`. | En vivo con el profesor; sin registro. |
| 6 | `docker compose start mongo`, esperar unos segundos y recargar. | "Funcionamiento normal". | En vivo con el profesor; sin registro. |

- **Evidencia:** paso 1 en el [informe de la corrida](resultados/pruebas_20261026_201329.txt), sección EC-01; pasos 2 a 6 sin evidencia escrita.
- **Estado:** Paso 1: Pasa. Pasos 2 a 6: en vivo con el profesor, sin registro.
- **Fecha y entorno:** paso 1: 2026-10-26, servidor del curso, con `scripts/pruebas/correr.php`; pasos 2 a 6: _(completar: fecha de la prueba en vivo)_

### EC-02 · Con Redis caído, la aplicación sigue funcionando

- **Objetivo:** comprobar que sin Redis se puede ver el portal, reservar, confirmar, cancelar e
  iniciar sesión, solo con MongoDB.
- **Precondiciones:** `docker compose pause redis`. Al terminar, `docker compose unpause redis`.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Ventana A: abrir cualquier página. | Arriba aparece "Modo respaldo: Redis no responde. Los cupos se controlan directamente en MongoDB y no hay caché." | En vivo con el profesor; sin registro. |
| 2 | Ventana B: **Actividades**. | El listado carga y abajo dice "Datos desde MongoDB en … ms". | En vivo con el profesor; sin registro. |
| 3 | Ventana B: reservar en una actividad con cupo. | "Cupo reservado. … (resuelto solo en MongoDB, … ms)" y el medidor baja un cupo. | En vivo con el profesor; sin registro. |
| 4 | Ventana B: **Confirmar cupo** y después **Cancelar mi inscripción**. | Las dos funcionan; al cancelar, el cupo vuelve. | En vivo con el profesor; sin registro. |
| 5 | Ventana B: **Salir**. Intentar entrar 6 veces como `voluntario3@voluntacr.test` con una contraseña incorrecta y después con la correcta. | Nunca aparece "Demasiados intentos fallidos…": sin Redis no hay límite (D13). La última vez entra. | En vivo con el profesor; sin registro. |

- **Evidencia:** sin evidencia escrita; se hizo en vivo con el profesor.
- **Estado:** En vivo con el profesor, sin registro.
- **Fecha y entorno:** _(completar: fecha de la prueba en vivo)_

### EC-03 · Sin Redis no hay sobrecupo, ni con 500 solicitudes simultáneas

- **Objetivo:** comprobar que MongoDB solo, con `findAndModify` y la condición
  `cuposDisponibles > 0`, impide el sobrecupo bajo carga.
- **Precondiciones:** ninguna; el script apaga Redis en los procesos que reservan. Es la misma
  medición de [mediciones/cupos.md](../mediciones/cupos.md), solo en el modo sin Redis.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | `docker compose exec app php scripts/medir_cupos.php --modo=mongodb --solicitudes=500 --cupos=50 --repeticiones=3` | En cada repetición: "50 aceptadas, 50 en MongoDB, sobrecupo 0". | Igual en las 3 repeticiones: 50 aceptadas, 50 en MongoDB, sobrecupo 0. |

- **Evidencia:** [informe de la corrida](resultados/pruebas_20261026_201329.txt), sección EC-03.
- **Estado:** Pasa
- **Fecha y entorno:** 2026-10-26, servidor del curso, con `scripts/pruebas/correr.php`
- **Notas:** El script corrió el mismo comando directamente en el servidor, sin `docker compose exec`. El CSV quedó en el servidor: `docs/mediciones/cupos_20261026_201336.csv`.

### EC-04 · Cuando Redis vuelve, un contador desfasado se corrige solo

- **Objetivo:** comprobar que si el contador de Redis quedó más bajo que el real, la siguiente
  reserva lo corrige en lugar de rechazarla. Es lo que pasa cuando alguien cancela mientras Redis no
  responde: MongoDB devuelve el cupo, pero la clave de Redis no se puede borrar.
- **Precondiciones:** todo funcionando. Ventana A publica "Prueba EC-04" con **cupo 2**; copiar el
  identificador de la actividad desde la dirección de su detalle.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Ventana B: reservar en "Prueba EC-04". | "Cupo reservado. … (filtrado en Redis y confirmado en MongoDB, … ms)"; el medidor dice "1 cupo libre". | Igual (filtrado en Redis y confirmado en MongoDB, 2.89 ms). |
| 2 | `docker compose exec redis redis-cli SET "grupoX:voluntacr:cupos:<identificador>" 0` | `OK`. Es el estado que deja una cancelación con Redis caído: Redis cree que no quedan cupos, pero MongoDB tiene 1. | Igual: `OK`. |
| 3 | Ventana A: **Salir**, entrar como `voluntario2@voluntacr.test` y reservar en "Prueba EC-04". | "Cupo reservado. … (filtrado en Redis y confirmado en MongoDB, … ms)". Antes de esta corrección decía "Ya no quedan cupos en esta actividad." y así seguía hasta una hora. | Igual (filtrado en Redis y confirmado en MongoDB, 3.72 ms). |
| 4 | `docker compose exec redis redis-cli GET "grupoX:voluntacr:cupos:<identificador>"` | `"0"`: el contador se volvió a cargar desde MongoDB (1) y bajó con la reserva del paso 3. | Igual: `"0"`. |

- **Evidencia:** [informe de la corrida](resultados/pruebas_20261026_201329.txt), sección EC-04.
- **Estado:** Pasa
- **Fecha y entorno:** 2026-10-26, servidor del curso, con `scripts/pruebas/correr.php`
- **Notas:** Los pasos 2 y 4 se hicieron con el cliente de Redis de la aplicación en lugar de `redis-cli`.

### EC-05 · Con MongoDB caído, el listado sale de la caché y lo demás muestra 503

- **Objetivo:** comprobar qué sigue funcionando cuando la fuente de verdad no responde.
- **Precondiciones:** todo funcionando. Al terminar, `docker compose start mongo`.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Ventana B: abrir **Actividades** sin filtros. | Carga el listado; con eso quedan guardadas la caché (60 s) y la copia de respaldo (24 h) de esa página. | En vivo con el profesor; sin registro. |
| 2 | `docker compose stop mongo` y, antes de 60 s, recargar **Actividades**. | El listado se ve y abajo dice "Datos desde caché de Redis en … ms". | En vivo con el profesor; sin registro. |
| 3 | Esperar más de 60 s y recargar **Actividades**. | Tarda unos segundos y el listado se ve con "Datos desde copia de respaldo en Redis (MongoDB no responde) en … ms". | En vivo con el profesor; sin registro. |
| 4 | Filtrar por una provincia que no se haya consultado antes. | Página 503 "Servicio no disponible": "La base de datos principal no responde en este momento. Las reservas y los cambios quedan en pausa; el listado de actividades sigue disponible si estaba en caché." | En vivo con el profesor; sin registro. |
| 5 | Abrir el detalle de una actividad. | La misma página 503. | En vivo con el profesor; sin registro. |
| 6 | `docker compose start mongo`, esperar unos segundos y reservar en una actividad con cupo. | Todo funciona de nuevo, y `/estado` dice "Funcionamiento normal". | En vivo con el profesor; sin registro. |

- **Evidencia:** sin evidencia escrita; se hizo en vivo con el profesor.
- **Estado:** En vivo con el profesor, sin registro.
- **Fecha y entorno:** _(completar: fecha de la prueba en vivo)_

### EC-06 · La bitácora de eventos con Redis caído

- **Objetivo:** comprobar que el stream `eventos` anota las inscripciones y que, sin Redis, las
  inscripciones siguen funcionando aunque no se anoten.
- **Precondiciones:** todo funcionando.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Ventana B: reservar, confirmar y cancelar en una actividad; abrir `/estado`. | "Últimos eventos de inscripciones" muestra la cancelación, la confirmación y la reserva (de la más reciente a la más antigua), con el título de la actividad; la reserva dice "filtrada en Redis, … ms". | Igual (la reserva dice «filtrada en Redis, 2.59 ms»). |
| 2 | `docker compose pause redis`, reservar en otra actividad y abrir `/estado`. | La reserva funciona. La sección dice "No se puede leer mientras Redis no responda. Las inscripciones siguen funcionando, pero no se anotan." | En vivo con el profesor; sin registro. |
| 3 | `docker compose unpause redis`, esperar 30 s y abrir `/estado`. | La reserva del paso 2 no aparece: la bitácora es un registro de apoyo. La inscripción sí está en **Mis inscripciones**. | En vivo con el profesor; sin registro. |
| 4 | `docker compose exec redis redis-cli XLEN "grupoX:voluntacr:eventos"` y `docker compose exec redis redis-cli TTL "grupoX:voluntacr:eventos"` | `XLEN` da el mismo total que `/estado`; `TTL` da cerca de 2592000 (30 días). | Igual: `XLEN` da 332, el mismo total que `/estado`; `TTL` da 2592000. |

- **Evidencia:** pasos 1 y 4 en el [informe de la corrida](resultados/pruebas_20261026_201329.txt), sección EC-06; pasos 2 y 3 sin evidencia escrita.
- **Estado:** Pasos 1 y 4: Pasa. Pasos 2 y 3: en vivo con el profesor, sin registro.
- **Fecha y entorno:** pasos 1 y 4: 2026-10-26, servidor del curso, con `scripts/pruebas/correr.php`; pasos 2 y 3: _(completar: fecha de la prueba en vivo)_
- **Notas:** El paso 4 se hizo con el cliente de Redis de la aplicación en lugar de `redis-cli`.
