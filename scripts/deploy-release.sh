#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  printf 'Användning: %s /path/to/stuckbema-release.tar.gz\n' "$0" >&2
  exit 64
fi

ARCHIVE="$1"
BASE="${STUCKBEMA_BASE:-/var/www/stuckbema}"
PHP_FPM_SERVICE="${STUCKBEMA_PHP_FPM_SERVICE:-php8.4-fpm}"
NGINX_SERVICE="${STUCKBEMA_NGINX_SERVICE:-nginx}"
REVERB_SERVICE="${STUCKBEMA_REVERB_SERVICE:-stuckbema-reverb}"
RELEASE_ID="$(date +%Y%m%d-%H%M%S)"
RELEASE="${BASE}/releases/${RELEASE_ID}"
SHARED="${BASE}/shared"

if [[ ! -f "${ARCHIVE}" ]]; then
  printf 'Releasearkiv saknas: %s\n' "${ARCHIVE}" >&2
  exit 66
fi

mkdir -p \
  "${BASE}/releases" \
  "${SHARED}/storage/app" \
  "${SHARED}/storage/framework/cache" \
  "${SHARED}/storage/framework/sessions" \
  "${SHARED}/storage/framework/views" \
  "${SHARED}/storage/logs" \
  "${SHARED}/produkter" \
  "${RELEASE}"

tar -xzf "${ARCHIVE}" -C "${RELEASE}"

if [[ ! -f "${SHARED}/.env" ]]; then
  printf 'Saknar %s/.env. Deploy avbryts för att undvika start utan produktionshemligheter.\n' "${SHARED}" >&2
  rm -rf "${RELEASE}"
  exit 78
fi

ln -sfn "${SHARED}/.env" "${RELEASE}/.env"
rm -rf "${RELEASE}/storage"
ln -sfn "${SHARED}/storage" "${RELEASE}/storage"
rm -rf "${RELEASE}/public/produkter"
ln -sfn "${SHARED}/produkter" "${RELEASE}/public/produkter"

cd "${RELEASE}"
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

ln -sfn "${RELEASE}" "${BASE}/current"

if command -v systemctl >/dev/null 2>&1; then
  systemctl reload "${PHP_FPM_SERVICE}" || true
  systemctl reload "${NGINX_SERVICE}" || true
  systemctl restart "${REVERB_SERVICE}" || true
fi

printf 'Deployed release %s to %s/current\n' "${RELEASE_ID}" "${BASE}"
