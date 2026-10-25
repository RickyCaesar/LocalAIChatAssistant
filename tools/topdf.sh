#!/usr/bin/env bash
# Convert a document to PDF using the backend container's LibreOffice.
# Prints the host path of the resulting PDF.
#
#   tools/topdf.sh <input-host-path> [out-host-dir]
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
storage="$root/backend/storage/app"
in="${1:?usage: topdf.sh <input-path> [out-dir]}"
out="${2:-$storage/private/hermes-output}"

case "$in" in
  "$storage"/*) cin="/app/storage/app/${in#"$storage"/}" ;;
  *) echo "input harus di bawah $storage" >&2; exit 1 ;;
esac
case "$out" in
  "$storage"/*) cout="/app/storage/app/${out#"$storage"/}" ;;
  *) echo "out harus di bawah $storage" >&2; exit 1 ;;
esac

mkdir -p "$out"
docker exec ai-chat-backend soffice --headless --norestore \
  -env:UserInstallation=file:///tmp/lo-convert \
  --convert-to pdf --outdir "$cout" "$cin" >/dev/null

echo "$out/$(basename "${in%.*}").pdf"
