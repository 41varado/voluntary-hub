# Cambios

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/). Una versión por
seguimiento del curso.

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
