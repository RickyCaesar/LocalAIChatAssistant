#!/usr/bin/env bash
# Convert a document to PDF using the backend container's LibreOffice.
# Prints the host path of the resulting PDF.
#
#   tools/topdf.sh <input-host-path> [out-host-dir]
set -euo pipefail

in="${1:?usage: topdf.sh <input-path> [out-dir]}"
out="${2:-/home/ROG/MyAssistant/backend/storage/app/private/hermes-output}"

case "$in" in
  /home/ROG/*) ;;
  *) echo "input harus di bawah /home/ROG (agar terlihat oleh container)" >&2; exit 1 ;;
esac

# Container punya /home/ROG (ro) dan volume storage di /app/storage/app,
# jadi petakan folder keluaran ke path dalam container.
storage="/home/ROG/MyAssistant/backend/storage/app"
case "$out" in
  "$storage"/*) cout="/app/storage/app/${out#"$storage"/}" ;;
  *) cout="$out" ;;
esac

docker exec ai-chat-backend soffice --headless --norestore \
  -env:UserInstallation=file:///tmp/lo-convert \
  --convert-to pdf --outdir "$cout" "$in" >/dev/null

echo "$out/$(basename "${in%.*}").pdf"
