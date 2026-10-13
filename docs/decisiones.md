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
