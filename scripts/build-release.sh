#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELEASE_DIR="${ROOT}/storage/app/releases"
NAME="${1:-stuckbema-release-$(date +%Y%m%d-%H%M%S)}"
TARGET="${RELEASE_DIR}/${NAME}.tar.gz"
STAGING="${RELEASE_DIR}/.tmp-${NAME}"
DEPLOY_IGNORE="${ROOT}/.deployignore"

mkdir -p "${RELEASE_DIR}"
rm -rf "${STAGING}"
mkdir -p "${STAGING}"

if [[ ! -f "${DEPLOY_IGNORE}" ]]; then
  printf 'Saknar .deployignore i projektroten: %s\n' "${DEPLOY_IGNORE}" >&2
  exit 1
fi

for path in app artisan bootstrap composer.json composer.lock config database package.json package-lock.json public resources routes vendor vite.config.js; do
  if [[ -e "${ROOT}/${path}" ]]; then
    mkdir -p "${STAGING}/$(dirname "${path}")"
    rsync -a --delete --exclude-from="${DEPLOY_IGNORE}" "${ROOT}/${path}" "${STAGING}/$(dirname "${path}")/"
  fi
done

rm -rf \
  "${STAGING}/bootstrap/cache/packages.php" \
  "${STAGING}/bootstrap/cache/services.php" \
  "${STAGING}/public/produkter"
find "${STAGING}" -type f \( -name '*.sql' -o -name '*.sqlite' -o -name '*.zip' -o -name '*.tar' -o -name '*.tar.gz' -o -name '*.tgz' -o -name '*.gz' \) -delete

rm -rf "${STAGING}/storage"
mkdir -p \
  "${STAGING}/storage/app" \
  "${STAGING}/storage/framework/cache" \
  "${STAGING}/storage/framework/sessions" \
  "${STAGING}/storage/framework/views" \
  "${STAGING}/storage/logs" \
  "${STAGING}/bootstrap/cache"
touch \
  "${STAGING}/storage/app/.gitkeep" \
  "${STAGING}/storage/framework/cache/.gitkeep" \
  "${STAGING}/storage/framework/sessions/.gitkeep" \
  "${STAGING}/storage/framework/views/.gitkeep" \
  "${STAGING}/storage/logs/.gitkeep" \
  "${STAGING}/bootstrap/cache/.gitkeep"

php "${ROOT}/artisan" deploy:audit "${STAGING}" --profile=release
tar -czf "${TARGET}" -C "${STAGING}" .
rm -rf "${STAGING}"
printf '%s\n' "${TARGET}"
