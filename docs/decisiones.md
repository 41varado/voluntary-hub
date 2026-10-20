# Decisiones técnicas

Registro de qué se decidió, qué alternativas había y por qué. Se agrega una entrada por decisión.

## D1. Dos motores (perfil B): MongoDB + Redis, sin SQLite

- **Decisión:** MongoDB como fuente de verdad y Redis como acelerador.
- **Alternativas:** agregar SQLite para la asistencia sin conexión (perfil C).
- **Por qué:** la asistencia sin conexión ocurre en el navegador del coordinador, y el navegador
  no usa SQLite. Ponerlo en el servidor sería una integración artificial. El curso acepta dos
  motores si la arquitectura está mejor justificada. La asistencia sin conexión se resolverá con
  el almacenamiento del navegador (seguimiento 3).

## D2. MongoDB es la fuente de verdad de los cupos; Redis solo filtra

- **Decisión:** el cupo real es `actividades.cuposDisponibles` y se descuenta con `findAndModify`
  y la condición `cuposDisponibles > 0`. Redis tiene una copia que rechaza rápido cuando la
  actividad está llena.
- **Alternativas:** que Redis fuera el dueño del contador y se sincronizara con MongoDB después.
- **Por qué:** si Redis fuera el dueño, una caída de Redis perdería reservas o permitiría sobrecupo.
  Así, sin Redis el sistema es igual de correcto, solo más lento bajo carga.

## D3. El vencimiento de la reserva vive en MongoDB, no en el TTL de Redis

- **Decisión:** `inscripciones.expiraEn` + liberación al ver la actividad + cron cada minuto.
- **Alternativas:** una clave de Redis con TTL por reserva y notificaciones de vencimiento.
- **Por qué:** al vencer hay que devolver el cupo, y las notificaciones de Redis no garantizan
  entrega. Además debe funcionar con Redis caído.

## D4. Invalidar el contador borrándolo, no sumándole

- **Decisión:** cuando un cupo se devuelve, se borra `cupos:{actividad}` y se recarga desde MongoDB.
- **Alternativas:** `INCR` en Redis cada vez que se devuelve un cupo.
- **Por qué:** borrar siempre deja un valor correcto; sumar puede acumular errores si una de las
  dos escrituras falla.

## D5. Caché del listado invalidada por versión

- **Decisión:** la clave incluye un número de versión; cualquier cambio hace `INCR` de la versión.
- **Alternativas:** borrar las claves con `SCAN` + `DEL`; vencimiento sin invalidación.
- **Por qué:** una sola operación invalida todas las combinaciones de filtros, sin recorrer claves
  en un Redis compartido.

## D6. Sesiones en archivos de PHP, no en Redis

- **Por qué:** si Redis cae, nadie pierde su sesión. El costo es que con varios servidores
  habría que compartir sesiones (no aplica a este MVP).

## D7. PHP sin framework ni Composer, con la extensión `mongodb` directamente

- **Por qué:** el servidor del curso (Virtualmin) solo necesita PHP con dos extensiones; instalar
  es copiar archivos. El código es corto y cada integrante puede explicarlo completo.
- **Costo:** se escribió una clase pequeña (`src/Nucleo/Mongo.php`) en lugar de usar la librería oficial.

## D8. Módulos que se conectan por "ganchos"

- **Decisión:** cada módulo (`src/modulos/*`) registra sus rutas y escucha eventos
  (`cupos.cambiaron`, `actividad.actualizada`…) sin depender directamente de los demás.
- **Por qué:** cada integrante puede trabajar e integrar su módulo por separado.

## D9. Imágenes en disco, metadatos en MongoDB

- **Alternativas:** GridFS.
- **Por qué:** el servidor web entrega los archivos sin pasar por PHP ni por la base. GridFS queda
  como mejora si se necesita replicar las imágenes entre servidores.

## D10. MongoDB del servidor del curso como motor principal; Atlas solo como alternativa

- **Decisión:** usar el MongoDB que ya tiene el servidor del curso. Atlas queda documentado como
  alternativa: el código es el mismo y solo cambia `MONGO_URI`.
- **Alternativas evaluadas:**
  - *Atlas + Redis:* válida, pero cada consulta viaja hasta AWS Oregon y una reserva hace 4 o 5
    consultas. Además, la regla del curso permite entrar a Atlas solo desde la IP del servidor.
  - *MongoDB del servidor + Atlas + Redis:* serían dos copias del mismo motor sin un rol distinto,
    más el trabajo de sincronizarlas. Es una integración artificial.
  - *Solo Redis (o Redis + Atlas sin MongoDB):* Atlas **es** MongoDB, así que no elimina el motor.
    Redis solo no se acepta en el curso y no ofrece consultas, agregaciones ni validación de esquema.
- **Por qué:** menor latencia, sin dependencia de un servicio externo y administrado por el grupo.
  Atlas sirve si se quiere una demo pública en la nube (sección 7 del enunciado).

## D11. Tres roles y cuentas que se desactivan en lugar de borrarse

- **Decisión:** roles `voluntario`, `coordinador` y `admin`. El registro público solo crea
  voluntarios; la administración crea coordinadores y otros administradores. Las cuentas se
  desactivan (`activo: false`) en lugar de borrarse.
- **Alternativas:** borrar cuentas; permitir que la pantalla de usuarios asigne el rol de admin.
- **Por qué:** borrar dejaría inscripciones y horas sin dueño. Los administradores se crean en una
  pantalla aparte y con la contraseña de quien los crea, para que no se otorgue ese permiso por
  error. Nadie puede desactivarse a sí mismo, así siempre queda al menos un administrador activo.

## D12. La sesión se revisa contra MongoDB cada 60 segundos

- **Decisión:** en cada solicitud, como máximo cada `SESION_VERIFICAR_SEG` segundos, se lee la cuenta
  en MongoDB. Si fue desactivada, o si su contraseña se restableció después de iniciar sesión
  (`sesionesValidasDesde`), la sesión se cierra; si cambió su rol u organización, se actualiza.
- **Alternativas:** revisar en cada solicitud (una consulta más por página) o no revisar nunca (una
  cuenta desactivada seguiría entrando hasta cerrar sesión).
- **Por qué:** equilibrio entre seguridad y carga. Si MongoDB no responde, la sesión se mantiene.

## D13. Límite de intentos de inicio de sesión por correo, en Redis

- **Decisión:** tras 5 intentos fallidos con el mismo correo, ese correo queda bloqueado 15 minutos.
  El contador vive en Redis (`intentos:login:{md5(correo)}`, TTL de 15 minutos) y se borra al
  acertar la contraseña. Si Redis no responde, se puede iniciar sesión sin límite de intentos.
- **Alternativas:** contar por dirección IP; guardar los intentos en MongoDB; impedir el inicio de
  sesión mientras Redis esté caído.
- **Por qué:** por correo se protege la cuenta atacada sin bloquear a otras personas que comparten
  la misma IP (por ejemplo, la red de la universidad). El TTL de Redis borra el contador solo, sin
  escribir en la base principal en cada intento. Redis acelera, no es requisito: con Redis caído es
  mejor permitir el ingreso sin límite que dejar a todo el mundo afuera.
- **Costo:** no frena a quien prueba muchos correos distintos desde una misma IP.

## D14. La asistencia se registra solo para inscripciones confirmadas, desde 2 horas antes

- **Decisión:** la lista de asistencia incluye solo las inscripciones confirmadas y se habilita desde
  2 horas antes del inicio de la actividad. Cada asistencia guarda las horas que tenía la actividad
  en ese momento (`asistencia.horas`).
- **Alternativas:** incluir las reservas sin confirmar; permitir registrar la asistencia en cualquier
  momento; calcular las horas siempre con el valor actual de la actividad.
- **Por qué:** una reserva sin confirmar puede vencer y su cupo pasar a otra persona; solo la
  confirmada es un compromiso. Si la asistencia se pudiera registrar en cualquier momento, se
  podrían sumar horas de una actividad que todavía no ocurre; las 2 horas de margen permiten pasar
  lista mientras llega la gente. Guardar las horas evita que el historial de un voluntario cambie
  si después se edita la actividad.

## D15. Horas por tipo de actividad con aggregation pipeline, sin `$lookup`

- **Decisión:** "Mis horas" se calcula con `$match` → `$group` → `$sort` sobre `inscripciones`:
  agrupa por la copia de la actividad que guarda cada inscripción (`actividad.categoria`) y suma
  `asistencia.horas`.
- **Alternativas:** `$lookup` a `actividades` para obtener la categoría; guardar un total de horas en
  cada usuario y sumarle; traer las inscripciones y sumar en PHP.
- **Por qué:** con la copia embebida la consulta lee una sola colección, sin unir. Un total guardado
  se podría desfasar; calcularlo deja una sola fuente de verdad (ver "Embebido o referencia" en el
  [modelo de datos](modelo-datos.md)). MongoDB agrupa en el servidor y devuelve una fila por tipo
  de actividad, no todas las inscripciones.

## D16. Una rama por integrante e integración con pull request

- **Decisión:** cada integrante trabaja en su rama (`dev-<nombre>`) y sus cambios entran a `main` con
  un pull request que aprueba al menos otra persona.
- **Alternativas:** trabajar directo en `main`, como se hizo hasta el seguimiento 1; una rama por
  funcionalidad.
- **Por qué:** cada módulo tiene una persona responsable (D8), así que una rama por integrante
  alcanza y evita choques. Con el pull request alguien más lee cada cambio antes de que llegue a
  `main`, y `main` queda estable para crear los tags de cada seguimiento.
