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

## D17. Lo que el cliente integrado de Redis no tiene se ejecuta con `EVAL`

- **Decisión:** los comandos de hash, sorted set y stream (`HINCRBY`, `HGETALL`, `ZINCRBY`, `ZADD`,
  `ZREVRANGE`, `XADD`, `XREVRANGE`…) se ejecutan dentro de scripts Lua con `EVAL`, que existe tanto
  en la extensión phpredis como en el cliente integrado (`src/Nucleo/RedisPolyfill.php`).
- **Alternativas:** agregar cada comando al cliente integrado; exigir la extensión phpredis en el
  servidor; usar solo strings.
- **Por qué:** el mismo código funciona con los dos clientes, y el cliente integrado no crece con
  cada estructura nueva. Además, cada script hace varias operaciones en un solo viaje a Redis y sin
  que otra solicitud se meta en medio: sumar una visita y renovar su vencimiento, o anotar un evento
  y renovar el del stream. Con solo strings no se cumplirían las tres estructuras que pide el curso.
- **Costo:** si el Redis del servidor no permitiera `EVAL`, estas funciones se comportarían como con
  Redis caído (D18), y las reservas seguirían solo con MongoDB.

## D18. Visitas, ranking y bitácora de eventos son datos de apoyo

- **Decisión:** las visitas por actividad y día (hash `actividad:{id}:visitas`), el ranking de horas
  (sorted set `ranking:horas`) y la bitácora de inscripciones (stream `eventos`) son datos de apoyo.
  Si Redis no responde, no se cuentan visitas ni se anotan eventos, y el ranking se calcula en
  MongoDB; las reservas y la asistencia se guardan igual.
- **Alternativas:** escribirlos también en MongoDB; no permitir la operación mientras Redis no
  responda.
- **Por qué:** son datos para mostrar (visitas en el panel, puesto en "Mis horas", últimos eventos
  en `/estado`), no compromisos con nadie. Guardarlos en MongoDB sumaría una escritura a la base
  principal en cada visita y en cada inscripción, que es justo la carga que Redis le quita. Y
  detener una reserva porque no se pudo anotar el evento sería peor que perder el evento.
- **Costo:** las visitas y los eventos son la excepción a la regla de que ningún dato vive solo en
  Redis ([redis-claves.md](redis-claves.md)): los de mientras Redis no respondía se pierden. El
  ranking sí se reconstruye desde MongoDB (D20).

## D19. El contador de cupos se confirma en MongoDB antes de rechazar, como máximo cada 15 s

- **Decisión:** cuando el contador de Redis dice que la actividad está llena, la primera solicitud
  que lo encuentra así (una cada 15 s por actividad, con `SET NX` en `liberando:{actividad}`) libera
  las reservas vencidas en MongoDB, borra el contador y lo vuelve a cargar. Las demás se rechazan en
  Redis, como antes.
- **Alternativas:** confiar en el contador hasta que venza (1 h); confirmar en MongoDB cada rechazo;
  corregirlo solo con `scripts/reconciliar_cupos.php`.
- **Por qué:** si alguien cancela mientras Redis no responde, MongoDB devuelve el cupo, pero la clave
  de Redis no se puede borrar y el contador queda más bajo que el real: la actividad aparecía llena
  hasta por una hora. Confirmar cada rechazo lo corregiría, pero mandaría a MongoDB todas las
  solicitudes que sobran en una avalancha, que es justo lo que el contador evita. Con una
  confirmación cada 15 s el desfase dura poco y MongoDB sigue protegido. Se prueba en EC-04.

## D20. El ranking se reconstruye desde MongoDB cada hora

- **Decisión:** `ranking:horas` vence a la hora. Mientras existe, guardar la asistencia suma o resta
  horas con `ZINCRBY`; cuando no existe, la siguiente consulta lo reconstruye con una agregación
  sobre las asistencias y lo vuelve a guardar.
- **Alternativas:** reconstruirlo en cada consulta; mantenerlo solo con `ZINCRBY`, sin vencimiento;
  un cron que lo reconstruya.
- **Por qué:** si una asistencia se guarda con Redis caído, su `ZINCRBY` se pierde y el ranking queda
  desfasado. Con el vencimiento, el desfase dura como máximo una hora y se corrige solo, sin otro
  cron en el servidor. Reconstruirlo en cada consulta sería recorrer todas las asistencias cada vez
  que alguien abre "Mis horas"; sin vencimiento, un desfase quedaría para siempre.

## D21. La asistencia sin conexión se guarda en el navegador

- **Decisión:** al guardar la lista de asistencia, las marcas se guardan primero en el `localStorage`
  del navegador del coordinador. Si no hay señal, se reenvían solas al volver la conexión, al
  recargar la página y cada 30 s. Cada envío lleva la lista completa de presentes, así que repetirlo
  no duplica nada.
- **Alternativas:** SQLite como tercer motor (perfil C); IndexedDB con un service worker; no permitir
  pasar lista sin conexión.
- **Por qué:** cumple lo que prometía D1 sin agregar un tercer motor: la falta de señal ocurre en el
  navegador del coordinador, y ahí no hay SQLite. Para una lista de casillas, `localStorage` alcanza
  y funciona en cualquier navegador; un service worker agregaría bastante código para guardar unos
  pocos identificadores.
- **Costo:** las marcas quedan solo en ese navegador hasta que se envían. Si el navegador no permite
  guardarlas (por ejemplo, en algunas ventanas privadas), la página lo avisa y pide no cerrarla.

## D22. Las mediciones fuerzan los planes con `hint` y usan datos temporales

- **Decisión:** `medir_indice.php` compara los planes de la consulta del portal forzándolos con
  `hint` (`{$natural: 1}` para el recorrido sin índice), sobre 20 000 actividades sintéticas de una
  organización temporal que se borran al terminar. `medir_cupos.php` también usa una actividad
  temporal, y las mediciones sin Redis lo apagan solo dentro del script (`REDIS_DESHABILITADO=true`).
- **Alternativas:** borrar el índice, medir y volver a crearlo; medir solo con los datos de prueba;
  detener Redis para medir sin él.
- **Por qué:** borrar un índice o detener Redis afectaría a quien esté usando la base o el Redis
  compartido en ese momento, y si el script se interrumpe el índice queda sin crear. Con los datos
  de prueba (unas 40 actividades) todos los planes tardan casi lo mismo y la diferencia no se ve.
  Con `hint` cada plan se mide sobre los mismos datos, y la semilla fija (2026) genera siempre los
  mismos, así la medición se puede repetir.
