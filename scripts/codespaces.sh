#!/usr/bin/env bash
# Prepara el proyecto en GitHub Codespaces (o en cualquier máquina con Docker):
# crea el .env, levanta PHP + MongoDB + Redis, crea el esquema y carga los datos de prueba.
#
#   bash scripts/codespaces.sh
#
# Codespaces lo ejecuta solo la primera vez (ver .devcontainer/devcontainer.json).
set -euo pipefail
cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Se creó .env a partir de .env.example"
fi

# En Codespaces, los enlaces de los correos apuntan a la dirección reenviada del puerto 8080.
# Solo se cambia el valor de ejemplo (localhost): si alguien puso otra dirección, se respeta.
if [ -n "${CODESPACE_NAME:-}" ]; then
  url="https://${CODESPACE_NAME}-8080.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}"
  if grep -q '^APP_URL=' .env; then
    sed -i "s#^APP_URL=http://localhost:8080\$#APP_URL=${url}#" .env
  else
    echo "APP_URL=${url}" >> .env
  fi
  echo "APP_URL: $(grep '^APP_URL=' .env | cut -d= -f2-)"
fi

docker compose up -d --build

echo "Esperando a que MongoDB y Redis respondan..."
for intento in $(seq 1 30); do
  if docker compose exec -T app php scripts/probar_conexiones.php >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

docker compose exec -T app php scripts/probar_conexiones.php
docker compose exec -T app php scripts/crear_esquema.php
docker compose exec -T app php scripts/cargar_datos.php --limpiar

echo
echo "Listo. Abra la pestaña PORTS y entre al puerto 8080."
