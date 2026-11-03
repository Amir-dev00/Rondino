#!/usr/bin/env bash
# Build a clean deployable tree for cPanel FTPS upload (no ZIP extraction on host).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAGE="${1:-${ROOT}/deploy-staging}"

echo "==> Staging deployable app into ${STAGE}"
rm -rf "${STAGE}"
mkdir -p "${STAGE}"

copy_tree() {
  local src="$1"
  local dest="$2"
  mkdir -p "$(dirname "${dest}")"
  if command -v rsync >/dev/null 2>&1; then
    rsync -a "${src}" "${dest}"
  else
    cp -a "${src}" "${dest}"
  fi
}

for dir in app bootstrap config database public resources routes storage; do
  copy_tree "${ROOT}/${dir}/" "${STAGE}/${dir}/"
done

cp "${ROOT}/artisan" "${STAGE}/artisan"
cp "${ROOT}/composer.json" "${STAGE}/composer.json"
cp "${ROOT}/composer.lock" "${STAGE}/composer.lock"
cp "${ROOT}/.env.example" "${STAGE}/.env.example"

# Strip non-production / local artefacts from the staged tree
rm -f "${STAGE}/database/"*.sqlite || true
rm -rf "${STAGE}/storage/app/source-audit" || true
find "${STAGE}/storage/logs" -type f ! -name '.gitignore' -delete 2>/dev/null || true
find "${STAGE}/storage/framework/cache/data" -type f ! -name '.gitignore' -delete 2>/dev/null || true
find "${STAGE}/storage/framework/sessions" -type f ! -name '.gitignore' -delete 2>/dev/null || true
find "${STAGE}/storage/framework/views" -type f ! -name '.gitignore' -delete 2>/dev/null || true
rm -f "${STAGE}/.env" "${STAGE}/.env.backup" "${STAGE}/.env.production" || true
rm -rf "${STAGE}/public/hot" || true

# Production Composer deps into the staging tree
composer install \
  --working-dir="${STAGE}" \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction \
  --no-progress

# Ensure writable directory placeholders exist
mkdir -p \
  "${STAGE}/storage/app/public" \
  "${STAGE}/storage/framework/cache/data" \
  "${STAGE}/storage/framework/sessions" \
  "${STAGE}/storage/framework/views" \
  "${STAGE}/storage/logs" \
  "${STAGE}/bootstrap/cache"

# Required path checks
required=(
  "${STAGE}/artisan"
  "${STAGE}/vendor/autoload.php"
  "${STAGE}/public/index.php"
  "${STAGE}/public/.htaccess"
  "${STAGE}/public/css/app.css"
)
for f in "${required[@]}"; do
  if [[ ! -e "${f}" ]]; then
    echo "ERROR: missing required path: ${f}" >&2
    exit 1
  fi
done

if [[ -d "${STAGE}/public/build" ]]; then
  if [[ ! -f "${STAGE}/public/build/manifest.json" && ! -f "${STAGE}/public/build/.vite/manifest.json" ]]; then
    echo "ERROR: public/build present but Vite manifest missing" >&2
    exit 1
  fi
  echo "OK: Vite build manifest present"
else
  echo "NOTE: no public/build (runtime CSS is public/css/app.css via asset())"
fi

echo "==> Staging complete"
du -sh "${STAGE}" || true
