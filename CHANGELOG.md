# Cambios

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/). Una versión por
seguimiento del curso.

## [Sin publicar]

## [0.9.0] - 2026-11-03

Release candidate del seguimiento 4.

### Agregado
- `portal.yml`, la ficha para el portal de proyectos del curso.
- `scripts/mongosh/crear_esquema.js`: el mismo esquema de `crear_esquema.php` (colecciones,
  validadores JSON Schema e índices) para mongosh. Lee la URI del `.env` y abre la conexión él mismo,
  así la URI no se escribe en la línea de comandos (D26).
- `scripts/probar_conexiones.php` avisa si faltan las extensiones `mbstring` o `fileinfo` de PHP: sin
  ellas los scripts funcionan, pero las páginas fallan.
- README: capturas, roadmap, instalación con GitHub Codespaces, notas para Windows, cómo comprobar la
  instalación, todas las variables de entorno y las secciones de pruebas y de cómo contribuir.
- Diagrama de arquitectura final (`docs/img/arquitectura.png`), con el módulo de avisos, Mailjet y
  las cuatro estructuras de Redis.
- Capturas de las pruebas con un motor detenido (EC-01, EC-02, EC-05 y EC-06) y del paso 3 de CN-03,
  en `docs/pruebas/img/`.
- `docs/redis-claves.md`: verificación final del mapa de claves y de las reglas del Redis compartido.
- Decisión D26 y bitácora del seguimiento 4 (`docs/seguimientos/S4.md`), con la prueba de
  instalación desde cero.

### Cambiado
- Las confirmaciones (cancelar o eliminar una actividad, eliminar una imagen, desactivar una cuenta,
  enviar un aviso y cancelar una inscripción) se hacen en un diálogo con el diseño de la aplicación
  y ya no en la ventana del navegador.
- Iniciar sesión, subir imágenes y restablecer una contraseña muestran sus errores en la página, como
  los demás formularios, en vez de los globos de validación del navegador.
- README: los requisitos piden las extensiones `mbstring` y `fileinfo`, y la tabla de scripts incluye
  las tres mediciones y el script de mongosh.
- `docs/arquitectura/README.md`: el diagrama muestra todas las claves de Redis, sus etiquetas ya no
  empiezan con «1.» (mermaid 11 las toma como una lista y muestra «Unsupported markdown: list») y la
  sección de mediciones tiene los resultados.
- `docs/despliegue-virtualmin.md`: revisión de las extensiones `mbstring` y `fileinfo`, y el esquema
  con mongosh.
- LICENSE: los nombres de los cuatro integrantes.
- Bitácora S1: nombres de los integrantes, números de issue corregidos y enlaces a los commits del
  avance de S2.

### Corregido
- Al iniciar sesión, la administración y la coordinación podían terminar en «403 Sin permiso»: se
  las devolvía a la última página que se pidió sin sesión en ese navegador, aunque fuera de otro rol
  (por ejemplo, la bandeja de avisos de un voluntario). Ahora solo se vuelve a esa página si la
  cuenta tiene el rol que pide; si no, va al inicio de su rol.
- `docs/despliegue-virtualmin.md`: el `git clone` apuntaba a `USUARIO/voluntacr`, un repositorio que
  no existe.
- La lista de asistencia y la página de avisos de una actividad repetían el punto después de la hora
  («2:30 p. m.. Cada asistencia…»).
- Con `RESERVA_MINUTOS=1`, la reserva decía «Confírmelo en los próximos 1 minutos»; ahora dice «en el
  próximo minuto».

## [0.3.0] - 2026-10-27

### Agregado
- Visitas por actividad y por día en un hash de Redis (`actividad:{id}:visitas`), visibles en el
  panel del coordinador.
- Bitácora de reservas, confirmaciones, cancelaciones y vencimientos en un stream de Redis
  (`eventos`); `/estado` muestra los últimos 10.
- Ranking de voluntarios por horas en un sorted set de Redis (`ranking:horas`), visible en "Mis
  horas". Se reconstruye desde MongoDB cada hora y, si Redis no responde, se calcula en MongoDB.
- Asistencia sin conexión: si no hay señal al guardar la lista, las marcas quedan en el navegador y
  se envían solas al volver la conexión.
- Pruebas documentadas en `docs/pruebas/`: caso normal (CN-01 a CN-03), error de conexión (EC-01 a
  EC-06) y datos inválidos (DI-01 a DI-09), con los resultados del 26 de octubre.
- Pruebas automatizadas de `docs/pruebas` (`scripts/pruebas/correr.php`): siguen por HTTP los pasos
  del caso normal, el error de conexión y los datos inválidos, y guardan el resultado de cada paso en
  `docs/pruebas/resultados/`. Los pasos que necesitan detener un motor se omiten.
- Mediciones en `docs/mediciones/`, con sus resultados e interpretación: reservas simultáneas con y
  sin Redis, listado con y sin caché (`scripts/medir_listado.php`) y consulta del portal con y sin
  índice (`scripts/medir_indice.php`).
- Las cuatro agregaciones de la aplicación, documentadas con `explain()` en `docs/modelo-datos.md`.
- Confirmación del correo con un enlace que envía Mailjet (Send API v3.1): las cuentas nuevas no
  pueden iniciar sesión hasta abrirlo. El enlace vence en 48 horas y sirve una sola vez; se puede
  pedir otro desde «Confirme su correo» o desde la ficha de la cuenta en Administración.
- Módulo de avisos (`src/modulos/notificaciones/`):
  - bandeja «Avisos» con un contador de no leídos para los voluntarios;
  - avisos automáticos a los inscritos cuando una actividad se cancela, cambia de fecha, lugar,
    horas o requisitos, sale del portal o se vuelve a publicar, y cuando se registra o se corrige
    su asistencia;
  - mensajes de la organización a todos los inscritos de una actividad, con su historial de lecturas.
- Colección `notificaciones` con validación JSON Schema y cuatro índices; campos de confirmación
  del correo y de preferencia de avisos en `usuarios`.
- Cliente de Mailjet sin Composer (`src/Nucleo/Correo.php`), `scripts/enviar_correos.php` para
  reintentar los correos (cron cada 5 minutos), sección de correo en `/estado` y prueba de Mailjet
  en `scripts/probar_conexiones.php`.
- «Mi cuenta»: el voluntario elige si recibe los avisos también por correo.
- «Acerca»: integrantes del equipo y enlace al repositorio.
- Decisiones técnicas D13 a D25 y bitácora del seguimiento 3 (`docs/seguimientos/S3.md`).

### Cambiado
- Arquitectura y mapa de claves de Redis: las cuatro estructuras en uso y qué hace la aplicación con
  cada una si Redis no responde.
- `.env.example`: `APP_REPOSITORIO` apunta al repositorio del equipo.
- El registro ya no inicia sesión: primero hay que confirmar el correo. Las cuentas anteriores, las
  de los scripts de consola y las de los datos de prueba cuentan como confirmadas.
- Una actividad cancelada se puede abrir por enlace (sigue fuera del portal) y dice que se canceló.
- `.env.example`: `APP_URL` y las variables de Mailjet. `scripts/codespaces.sh` pone en `APP_URL` la
  dirección del Codespace.
- README: los avisos por correo salen del roadmap, y el nombre de Derek se escribe como en el resto
  del repositorio.

### Corregido
- Un contador de cupos que quedaba más bajo que el real después de una caída de Redis hacía ver la
  actividad llena hasta por una hora; ahora se confirma en MongoDB, como máximo cada 15 s, antes de
  rechazar la reserva.
- README: el `git clone` apuntaba a un repositorio que no existe, y la extensión `redis` de PHP
  figuraba como obligatoria.

## [0.2.0] - 2026-10-20

### Agregado
- Entorno de demo en GitHub Codespaces (`.devcontainer/`, `scripts/codespaces.sh`).
- Cliente de Redis en PHP puro (`src/Nucleo/RedisPolyfill.php`) para servidores sin la extensión
  `redis`; `/estado` indica qué cliente se está usando.
- Bitácora del seguimiento 2 (`docs/seguimientos/S2.md`).

### Cambiado
- README: sin la sección de roadmap y con la redacción de la sección de MongoDB corregida.

## [0.1.0] - 2026-10-13

### Agregado
- Estructura del repositorio, licencia MIT, `.gitignore`, `.env.example` y Docker para desarrollo.
- README inicial con problema, usuarios y alcance del MVP.
- Diagrama de arquitectura v1, modelo de datos v1, mapa de claves de Redis y primeras decisiones técnicas.
- Conexión a MongoDB y a Redis; modo respaldo cuando Redis no responde.
- Página `/estado` y `scripts/probar_conexiones.php` ("hola mundo").
- Script de esquema con validación JSON Schema e índices.
- Fuente de datos sintética (`data/`) y generador reproducible (`scripts/cargar_datos.php`).
- Roles de voluntario, coordinación y administración; pantallas de Usuarios y Administradores;
  "Mi cuenta" con cambio de contraseña; cuentas que se desactivan en lugar de borrarse.
- Avance del seguimiento 2: portal de actividades con filtros y caché, panel del coordinador con
  imágenes, reservas de cupo sin sobrecupo, cuentas de usuario, asistencia e historial de horas.

[Sin publicar]: https://github.com/41varado/voluntary-hub/compare/v0.9.0...HEAD
[0.9.0]: https://github.com/41varado/voluntary-hub/compare/v0.3.0...v0.9.0
[0.3.0]: https://github.com/41varado/voluntary-hub/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/41varado/voluntary-hub/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/41varado/voluntary-hub/tree/v0.1.0
