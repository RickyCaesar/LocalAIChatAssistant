#!/usr/bin/env bash
# Naikkan stack + proxy notulen Hermes (proxy hanya bisa jalan di host).
set -euo pipefail

cd "$(dirname "$0")/.."

if ! curl -fsS -m 2 http://127.0.0.1:8645/health >/dev/null 2>&1; then
    echo "Menjalankan proxy notulen Hermes di :8645..."
    HERMES_HOME="${HERMES_HOME:-$HOME/.hermes}" \
        nohup hermes proxy start --provider nous >"$HOME/.hermes/logs/proxy.log" 2>&1 &
fi

docker compose up -d
docker compose ps
