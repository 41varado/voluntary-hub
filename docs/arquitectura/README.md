# Arquitectura v1

VoluntaCR es una aplicación web en PHP (sin framework ni Composer) con dos motores de datos:
**MongoDB** (el instalado en el servidor del curso) como fuente de verdad y **Redis** como
acelerador. MongoDB Atlas es el mismo motor en la nube y queda como alternativa cambiando solo la
URI; no se usan los dos a la vez (decisión D10). Es un proyecto de **perfil B**
(dos motores): cada motor tiene un rol claro, se explica cómo se mantienen consistentes y qué
pasa si uno de los dos falla.

## Diagrama

```mermaid
flowchart LR
    V["Voluntario<br/>(navegador)"]
    C["Coordinador de la ONG<br/>(navegador)"]
    AD["Administrador<br/>(navegador)"]

    subgraph APP["Aplicación PHP · public/index.php"]
        direction TB
        M1["Actividades<br/>portal, panel, imágenes"]
        M2["Inscripciones<br/>reservar, confirmar, cancelar"]
        M3["Usuarios<br/>sesión, mi cuenta, horas, asistencia"]
        M4["Administración<br/>usuarios y administradores"]
        N["Núcleo<br/>Mongo.php · RedisSeguro.php"]
        M1 --> N
        M2 --> N
        M3 --> N
        M4 --> N
    end

    subgraph R["Redis · acelerador"]
        R1["cupos:{actividad}<br/>contador (Lua)"]
        R2["candado:reserva:*<br/>SET NX, 5 s"]
        R3["cache:actividades:*<br/>JSON, 60 s"]
        R4["intentos:login:*<br/>contador, 15 min"]
    end

    subgraph MDB["MongoDB · fuente de verdad"]
        D1[("organizaciones")]
        D2[("usuarios<br/>voluntario · coordinador · admin")]
        D3[("actividades<br/>+ metadatos de imágenes")]
        D4[("inscripciones<br/>+ asistencia")]
    end

    F[["public/uploads/<br/>archivos de imagen"]]
    CRON["cron: liberar_reservas.php<br/>cada minuto"]

    V --> APP
    C --> APP
    AD --> APP
    N -->|1. filtro rápido| R
    N -->|2. operación atómica| MDB
    M1 -->|guarda archivos| F
    CRON --> MDB
    N -.->|si Redis no responde,<br/>todo va directo a MongoDB| MDB
```

## Qué dato vive en cada motor y por qué

| Dato | Motor | Por qué |
|---|---|---|
| Organizaciones, usuarios y sus roles, actividades, inscripciones, asistencia | MongoDB | Es lo que no se puede perder. Las actividades tienen campos variables (imágenes, requisitos) y la inscripción guarda una copia de la actividad para el historial: el modelo documental encaja sin tablas intermedias. |
| Metadatos de las imágenes | MongoDB (dentro de la actividad) | Siempre se leen junto con la actividad y son pocas (máximo 6). |
| Archivos de imagen | Disco (`public/uploads/`) | El servidor web los entrega directo; guardarlos en la base solo agregaría carga. |
| Contador de cupos por actividad | Redis | Un script Lua resta un cupo solo si quedan, en una operación atómica de microsegundos. Cuando la actividad se llena, las solicitudes se rechazan sin tocar MongoDB. |
| Candado contra doble envío | Redis | `SET NX` con vencimiento de 5 s: el doble clic no genera dos reservas. |
| Listado de actividades en caché | Redis | Es la página más visitada; se guarda 60 s y se invalida al cambiar algo. |
| Copia de respaldo del listado | Redis | Si MongoDB cae, el portal sigue mostrando la última versión conocida (24 h). |
| Intentos fallidos de inicio de sesión | Redis | Contador con vencimiento de 15 min para frenar ataques por fuerza bruta. |
| Sesiones de usuario | Archivos de PHP | Decisión consciente: si Redis cae, nadie pierde su sesión. |

El detalle de colecciones y claves está en [modelo-datos.md](../modelo-datos.md) y [redis-claves.md](../redis-claves.md).

## Flujo principal: reservar un cupo

```mermaid
sequenceDiagram
    autonumber
    actor V as Voluntario
    participant A as Aplicación
    participant R as Redis
    participant M as MongoDB
    V->>A: Reservar cupo
    A->>R: SET candado NX EX 5
    A->>R: EVAL tomar_cupo (GET y DECR si > 0)
    alt contador en 0
        R-->>A: -1 (llena)
        A-->>V: Ya no quedan cupos (sin consultar MongoDB)
    else quedaba cupo
        R-->>A: cupos restantes
        A->>M: findAndModify {cuposDisponibles > 0} $inc -1
        M-->>A: actividad actualizada
        A->>M: insertar inscripción (reservada, expiraEn = ahora + 10 min)
        A-->>V: Cupo apartado, confirme en 10 minutos
    end
    Note over A,M: Si Redis no responde, se omiten los pasos 2 y 3.<br/>MongoDB sigue garantizando que no haya sobrecupo.
```

## ¿Qué ocurre si Redis se cae?

1. `RedisSeguro` intenta conectar con un tiempo límite de 0,5 s. Si falla, anota la hora en
   `storage/redis_caido_hasta.txt` y durante 30 s ninguna solicitud vuelve a intentarlo
   (interruptor o *circuit breaker*), para no esperar en cada página.
2. Las reservas van directo a MongoDB: la operación `findAndModify` con la condición
   `cuposDisponibles > 0` sigue impidiendo el sobrecupo. Solo se pierde velocidad bajo carga.
3. El listado se consulta en MongoDB en cada visita (sin caché).
4. No hay límite de intentos de inicio de sesión mientras Redis esté caído.
5. Los coordinadores ven una franja que avisa "Modo respaldo", y `/estado` lo muestra.
6. Cuando Redis vuelve, los contadores se cargan de nuevo desde MongoDB la primera vez que se usan.

## ¿Qué ocurre si MongoDB se cae?

MongoDB es la fuente de verdad, así que no se puede reservar ni modificar nada (página 503 con
un mensaje claro). El listado de actividades se sigue mostrando desde la caché de Redis o desde su
copia de respaldo, y `/estado` indica "MongoDB no responde".

## ¿Cómo se mantienen consistentes?

- **Contador de cupos:** MongoDB tiene el valor real (`cuposDisponibles`). Redis tiene una copia
  que se carga la primera vez (`SET NX` con 1 h de vencimiento). Cada vez que un cupo se devuelve
  (cancelación, reserva vencida, cambio del cupo total) la clave de Redis se **borra** y se vuelve
  a cargar en la siguiente reserva. Si Redis deja pasar una solicitud de más, MongoDB la rechaza y
  la clave también se borra.
- **Caché del listado:** se invalida por versión. Cualquier cambio incrementa
  `cache:actividades:version`; las claves viejas dejan de usarse y expiran solas a los 60 s.
- **Copia de la actividad dentro de la inscripción:** cuando el coordinador edita la actividad, se
  actualiza en todas sus inscripciones (gancho `actividad.actualizada`).
- **Red de seguridad:** `scripts/reconciliar_cupos.php` recalcula los cupos desde las inscripciones.

## Qué se va a medir

Ver la sección de mediciones del [README](../../README.md#mediciones). La principal:
500 inscripciones simultáneas sobre una actividad de 50 cupos, con Redis y sin Redis
(`scripts/medir_cupos.php`), comparando tiempo total, latencia y sobrecupo.
