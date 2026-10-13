#!/usr/bin/env bash
# Crea en GitHub los hitos (uno por seguimiento), las etiquetas y los issues del backlog,
# cada uno con su responsable. Requiere GitHub CLI (https://cli.github.com) con sesión iniciada:
#
#   gh auth login
#   P1=usuario1 P2=usuario2 P3=usuario3 P4=usuario4 bash scripts/crear_backlog.sh
#
# P1..P4 son los usuarios de GitHub de cada integrante (ver la tabla de roles en el README).
# Ejecútelo una sola vez, desde la carpeta del repositorio.
# Responsable: Persona 4.
set -euo pipefail

: "${P1:?Falta P1 (usuario de GitHub de la persona 1)}"
: "${P2:?Falta P2}"
: "${P3:?Falta P3}"
: "${P4:?Falta P4}"

repo=$(gh repo view --json nameWithOwner -q .nameWithOwner)
echo "Repositorio: $repo"

hito() { # titulo fecha descripcion
  gh api "repos/$repo/milestones" -f title="$1" -f due_on="$2T23:59:59Z" -f description="$3" >/dev/null 2>&1 \
    && echo "  hito: $1" || echo "  hito ya existía: $1"
}
hito "S1 · Problema, arquitectura y repositorio" "2026-10-13" "Tag v0.1.0 + bitácora S1"
hito "S2 · Núcleo funcional"                     "2026-10-20" "Tag v0.2.0 + bitácora S2"
hito "S3 · Integración, pruebas y medición"      "2026-10-27" "Tag v0.3.0 + bitácora S3"
hito "S4 · Release candidate"                    "2026-11-03" "Tag v0.9.0 o v1.0.0 + bitácora S4"

etiqueta() { gh label create "$1" --color "$2" --force >/dev/null && echo "  etiqueta: $1"; }
etiqueta mongodb    "13aa52"
etiqueta redis      "d82c20"
etiqueta interfaz   "f2b705"
etiqueta docs       "0e8a16"
etiqueta pruebas    "5319e7"
etiqueta despliegue "1d76db"

issue() { # hito responsable etiquetas titulo cuerpo
  gh issue create --milestone "$1" --assignee "$2" --label "$3" --title "$4" --body "$5" >/dev/null
  echo "  issue: $4"
}

S1="S1 · Problema, arquitectura y repositorio"
issue "$S1" "$P1" docs        "Repositorio con licencia MIT, .gitignore y estructura de carpetas" "src/, docs/, data/, scripts/, public/. Colaboradores agregados."
issue "$S1" "$P1" docs        "README inicial: problema, usuarios y alcance del MVP" "Qué entra y qué no entra en el MVP."
issue "$S1" "$P2" "docs,mongodb" "Diagrama de arquitectura v1" "docs/arquitectura: aplicación, motores y qué dato vive en cada uno."
issue "$S1" "$P2" mongodb     "Modelo de datos v1 y script de esquema" "Colecciones, embebido vs. referencia, JSON Schema e índices (scripts/crear_esquema.php)."
issue "$S1" "$P3" "redis,docs" "Diseño de claves de Redis" "Prefijo grupoX:, estructuras y TTL de cada clave (docs/redis-claves.md)."
issue "$S1" "$P3" "redis,mongodb" "Hola mundo: conexión a MongoDB y Redis con tolerancia a fallos" "Página /estado y scripts/probar_conexiones.php."
issue "$S1" "$P4" docs        "Fuente de datos y generador sintético" "data/ y docs/fuente-datos.md."
issue "$S1" "$P4" docs        "Backlog en GitHub con hitos y responsables" "scripts/crear_backlog.sh."
issue "$S1" "$P1" docs        "Bitácora S1, CHANGELOG y tag v0.1.0" "docs/seguimientos/S1.md antes de las 6:00 p. m."
issue "$S1" "$P1" "interfaz,mongodb" "Roles y pantallas de administración" "Usuarios (crear, editar, desactivar, restablecer clave) y Administradores (agregar con confirmación, desactivar)."
issue "$S1" "$P4" interfaz    "Mi cuenta: datos personales y cambio de contraseña" "Para todos los roles; cierra las otras sesiones al cambiar la clave."

S2="S2 · Núcleo funcional"
issue "$S2" "$P2" "mongodb,interfaz" "CRUD de actividades desde el panel del coordinador" "Crear, editar, publicar, cancelar y eliminar."
issue "$S2" "$P2" "interfaz,redis" "Imágenes de la actividad y caché del listado" "Subida validada, portada, galería; caché con TTL e invalidación por versión."
issue "$S2" "$P3" "redis,mongodb" "Reservar, confirmar y cancelar cupo sin sobrecupo" "Filtro en Redis (Lua) + findAndModify en MongoDB; respaldo si Redis cae."
issue "$S2" "$P3" mongodb     "Liberación de reservas vencidas y reconciliación de cupos" "scripts/liberar_reservas.php (cron) y scripts/reconciliar_cupos.php."
issue "$S2" "$P4" "interfaz,redis" "Registro, inicio de sesión y límite de intentos" "Contraseñas con password_hash; 5 intentos por correo cada 15 min en Redis."
issue "$S2" "$P4" "interfaz,mongodb" "Asistencia del coordinador e historial de horas" "Horas calculadas con aggregation pipeline."
issue "$S2" "$P4" mongodb     "Script de carga reproducible" "scripts/cargar_datos.php con semilla."
issue "$S2" "$P1" despliegue  "Despliegue en el servidor del curso" ".env en el servidor, Atlas o MongoDB local, Redis compartido con prefijo, cron."

S3="S3 · Integración, pruebas y medición"
issue "$S3" "$P3" "pruebas,redis" "Medición: reservas simultáneas con y sin Redis" "scripts/medir_cupos.php, 3 repeticiones, mediana. Tabla en el README."
issue "$S3" "$P2" "pruebas,redis" "Medición: listado con y sin caché" "3 repeticiones, mediana."
issue "$S3" "$P2" "pruebas,mongodb" "Medición: consulta del portal con y sin índice (explain)" "Con más volumen de datos."
issue "$S3" "$P3" redis       "Redis: ranking de horas, visitas por actividad y bitácora de eventos" "Sorted set, hash y stream (al menos 3 estructuras)."
issue "$S3" "$P4" interfaz    "Asistencia sin conexión en el navegador" "Guardar marcas en el navegador y enviarlas al volver la señal."
issue "$S3" "$P1" pruebas     "Pruebas documentadas" "Caso normal, error de conexión (Redis y MongoDB caídos) y datos inválidos."
issue "$S3" "$P1" docs        "Completar docs/decisiones.md" "Una entrada por decisión, con alternativas."

S4="S4 · Release candidate"
issue "$S4" "$P1" docs        "Instalación desde cero siguiendo solo el README" "La hace un integrante que no configuró el proyecto."
issue "$S4" "$P1" docs        "README completo, CHANGELOG y portal.yml" "Capturas, variables, roadmap."
issue "$S4" "$P2" docs        "Video de demostración (3 a 5 min)" "Enlazado en el README."
issue "$S4" "$P3" docs        "Borrador de la presentación final" ""

echo "Listo. Revise el tablero en https://github.com/$repo/issues"
