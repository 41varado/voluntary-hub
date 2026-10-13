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
