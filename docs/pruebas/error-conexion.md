# Pruebas · Error de conexión

Responsable: Manuel Mora Sandi (Persona 3). Formato y reglas en [README.md](README.md). Qué hace la
aplicación en cada caso está en [arquitectura](../arquitectura/README.md#qué-ocurre-si-redis-se-cae).

| Prueba | Qué comprueba | Estado |
|---|---|---|
| EC-01 | `/estado` en los tres modos: normal, respaldo (sin Redis) y crítico (sin MongoDB) | Pendiente |
| EC-02 | Con Redis caído, el portal, las reservas y el inicio de sesión siguen funcionando | Pendiente |
| EC-03 | Sin Redis no hay sobrecupo, ni con 500 solicitudes simultáneas | Pendiente |
| EC-04 | Cuando Redis vuelve, un contador de cupos desfasado se corrige solo | Pendiente |
| EC-05 | Con MongoDB caído, el listado sale de la caché y lo demás muestra una página 503 | Pendiente |
| EC-06 | La bitácora de eventos con Redis caído | Pendiente |

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
| 1 | Con todo funcionando, abrir `/estado`. | "Funcionamiento normal"; MongoDB y Redis dicen "Conectado". | |
| 2 | `docker compose pause redis` y recargar `/estado`. | Tarda medio segundo más. "Modo respaldo"; Redis dice "Sin conexión" con el motivo "Redis no responde (…). Modo respaldo con MongoDB durante 30 s."; MongoDB sigue "Conectado". | |
| 3 | Recargar otra vez. | Responde enseguida, con el motivo "Redis falló hace poco; se volverá a intentar en N s.". | |
| 4 | `docker compose unpause redis`, esperar 30 s y recargar. | "Funcionamiento normal". | |
| 5 | `docker compose stop mongo` y recargar `/estado`. | Tarda unos segundos. "MongoDB no responde"; MongoDB dice "Sin conexión". `curl -s -o /dev/null -w "%{http_code}\n" localhost:8080/estado.json` muestra `503`. | |
| 6 | `docker compose start mongo`, esperar unos segundos y recargar. | "Funcionamiento normal". | |

- **Evidencia:** `img/EC-01-paso-1.png` (normal), `img/EC-01-paso-2.png` (respaldo) y
  `img/EC-01-paso-5.png` (crítico).
- **Estado:** Pendiente
- **Fecha y entorno:**

### EC-02 · Con Redis caído, la aplicación sigue funcionando

- **Objetivo:** comprobar que sin Redis se puede ver el portal, reservar, confirmar, cancelar e
  iniciar sesión, solo con MongoDB.
- **Precondiciones:** `docker compose pause redis`. Al terminar, `docker compose unpause redis`.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Ventana A: abrir cualquier página. | Arriba aparece "Modo respaldo: Redis no responde. Los cupos se controlan directamente en MongoDB y no hay caché." | |
| 2 | Ventana B: **Actividades**. | El listado carga y abajo dice "Datos desde MongoDB en … ms". | |
| 3 | Ventana B: reservar en una actividad con cupo. | "Cupo reservado. … (resuelto solo en MongoDB, … ms)" y el medidor baja un cupo. | |
| 4 | Ventana B: **Confirmar cupo** y después **Cancelar mi inscripción**. | Las dos funcionan; al cancelar, el cupo vuelve. | |
| 5 | Ventana B: **Salir**. Intentar entrar 6 veces como `voluntario3@voluntacr.test` con una contraseña incorrecta y después con la correcta. | Nunca aparece "Demasiados intentos fallidos…": sin Redis no hay límite (D13). La última vez entra. | |

- **Evidencia:** `img/EC-02-paso-1.png` y `img/EC-02-paso-3.png`
- **Estado:** Pendiente
- **Fecha y entorno:**

### EC-03 · Sin Redis no hay sobrecupo, ni con 500 solicitudes simultáneas

- **Objetivo:** comprobar que MongoDB solo, con `findAndModify` y la condición
  `cuposDisponibles > 0`, impide el sobrecupo bajo carga.
- **Precondiciones:** ninguna; el script apaga Redis en los procesos que reservan. Es la misma
  medición de [mediciones/cupos.md](../mediciones/cupos.md), solo en el modo sin Redis.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | `docker compose exec app php scripts/medir_cupos.php --modo=mongodb --solicitudes=500 --cupos=50 --repeticiones=3` | En cada repetición: "50 aceptadas, 50 en MongoDB, sobrecupo 0". | |

- **Evidencia:** `img/EC-03-paso-1.png` (salida del script)
- **Estado:** Pendiente
- **Fecha y entorno:**

### EC-04 · Cuando Redis vuelve, un contador desfasado se corrige solo

- **Objetivo:** comprobar que si el contador de Redis quedó más bajo que el real, la siguiente
  reserva lo corrige en lugar de rechazarla. Es lo que pasa cuando alguien cancela mientras Redis no
  responde: MongoDB devuelve el cupo, pero la clave de Redis no se puede borrar.
- **Precondiciones:** todo funcionando. Ventana A publica "Prueba EC-04" con **cupo 2**; copiar el
  identificador de la actividad desde la dirección de su detalle.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Ventana B: reservar en "Prueba EC-04". | "Cupo reservado. … (filtrado en Redis y confirmado en MongoDB, … ms)"; el medidor dice "1 cupo libre". | |
| 2 | `docker compose exec redis redis-cli SET "grupoX:voluntacr:cupos:<identificador>" 0` | `OK`. Es el estado que deja una cancelación con Redis caído: Redis cree que no quedan cupos, pero MongoDB tiene 1. | |
| 3 | Ventana A: **Salir**, entrar como `voluntario2@voluntacr.test` y reservar en "Prueba EC-04". | "Cupo reservado. … (filtrado en Redis y confirmado en MongoDB, … ms)". Antes de esta corrección decía "Ya no quedan cupos en esta actividad." y así seguía hasta una hora. | |
| 4 | `docker compose exec redis redis-cli GET "grupoX:voluntacr:cupos:<identificador>"` | `"0"`: el contador se volvió a cargar desde MongoDB (1) y bajó con la reserva del paso 3. | |

- **Evidencia:** `img/EC-04-paso-3.png`
- **Estado:** Pendiente
- **Fecha y entorno:**

### EC-05 · Con MongoDB caído, el listado sale de la caché y lo demás muestra 503

- **Objetivo:** comprobar qué sigue funcionando cuando la fuente de verdad no responde.
- **Precondiciones:** todo funcionando. Al terminar, `docker compose start mongo`.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Ventana B: abrir **Actividades** sin filtros. | Carga el listado; con eso quedan guardadas la caché (60 s) y la copia de respaldo (24 h) de esa página. | |
| 2 | `docker compose stop mongo` y, antes de 60 s, recargar **Actividades**. | El listado se ve y abajo dice "Datos desde caché de Redis en … ms". | |
| 3 | Esperar más de 60 s y recargar **Actividades**. | Tarda unos segundos y el listado se ve con "Datos desde copia de respaldo en Redis (MongoDB no responde) en … ms". | |
| 4 | Filtrar por una provincia que no se haya consultado antes. | Página 503 "Servicio no disponible": "La base de datos principal no responde en este momento. Las reservas y los cambios quedan en pausa; el listado de actividades sigue disponible si estaba en caché." | |
| 5 | Abrir el detalle de una actividad. | La misma página 503. | |
| 6 | `docker compose start mongo`, esperar unos segundos y reservar en una actividad con cupo. | Todo funciona de nuevo, y `/estado` dice "Funcionamiento normal". | |

- **Evidencia:** `img/EC-05-paso-3.png` y `img/EC-05-paso-4.png`
- **Estado:** Pendiente
- **Fecha y entorno:**

### EC-06 · La bitácora de eventos con Redis caído

- **Objetivo:** comprobar que el stream `eventos` anota las inscripciones y que, sin Redis, las
  inscripciones siguen funcionando aunque no se anoten.
- **Precondiciones:** todo funcionando.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Ventana B: reservar, confirmar y cancelar en una actividad; abrir `/estado`. | "Últimos eventos de inscripciones" muestra la cancelación, la confirmación y la reserva (de la más reciente a la más antigua), con el título de la actividad; la reserva dice "filtrada en Redis, … ms". | |
| 2 | `docker compose pause redis`, reservar en otra actividad y abrir `/estado`. | La reserva funciona. La sección dice "No se puede leer mientras Redis no responda. Las inscripciones siguen funcionando, pero no se anotan." | |
| 3 | `docker compose unpause redis`, esperar 30 s y abrir `/estado`. | La reserva del paso 2 no aparece: la bitácora es un registro de apoyo. La inscripción sí está en **Mis inscripciones**. | |
| 4 | `docker compose exec redis redis-cli XLEN "grupoX:voluntacr:eventos"` y `docker compose exec redis redis-cli TTL "grupoX:voluntacr:eventos"` | `XLEN` da el mismo total que `/estado`; `TTL` da cerca de 2592000 (30 días). | |

- **Evidencia:** `img/EC-06-paso-1.png` y `img/EC-06-paso-2.png`
- **Estado:** Pendiente
- **Fecha y entorno:**
