#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

APP_ROOT="${EKABINETI_ROOT:-/var/www/ekabineti-bitola}"
CONFIG_FILE="${EKABINETI_CONFIG:-${APP_ROOT}/shared/e-kabineti.php}"
[[ -r "${CONFIG_FILE}" ]] || { echo "Missing configuration: ${CONFIG_FILE}" >&2; exit 1; }

APP_URL="$(php -r '$c=require $argv[1]; echo rtrim((string)($c["app"]["url"]??""), "/");' "${CONFIG_FILE}")"
TOKEN="$(php -r '$c=require $argv[1]; echo trim((string)($c["app"]["health_token"]??""));' "${CONFIG_FILE}")"
[[ "${APP_URL}" == https://* ]] || { echo "app.url must use HTTPS" >&2; exit 1; }
[[ ${#TOKEN} -ge 32 ]] || { echo "health_token is missing" >&2; exit 1; }

RESPONSE="$(printf 'header = "Authorization: Bearer %s"\n' "${TOKEN}" | \
    curl --config - --fail --silent --show-error --request POST \
    --connect-timeout 10 --max-time 120 "${APP_URL}/internal/scheduler")"

php -r '$j=json_decode($argv[1],true);if(!is_array($j)||!in_array(($j["status"]??""),["ok","busy"],true)){exit(1);}' "${RESPONSE}"
printf '%s\n' "${RESPONSE}"
unset TOKEN RESPONSE
