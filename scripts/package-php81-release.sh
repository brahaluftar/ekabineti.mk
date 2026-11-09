#!/usr/bin/env bash
# Builds the e-Kabineti PHP 8.1 deployment package (Linux equivalent of scripts/windows/package-php81-release.ps1).
#
#   bash scripts/package-php81-release.sh [--version 2026.10.23.01] [--source php81] [--output dist]
#
# Produces <output>/e-kabineti-php81-<version>.zip and .zip.sha256. The package contains the php81 runtime
# without tests, private configuration, .env files or runtime storage content (storage/logs and storage/private
# keep only a .keep marker). ZIP entries always use POSIX '/' separators.
set -Eeuo pipefail
umask 022

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
project_root="$(cd "${script_dir}/.." && pwd -P)"
source_dir="${project_root}/php81"
output_dir="${project_root}/dist"
version=''

fail() { echo "[FAIL] $*" >&2; exit 1; }
while [[ $# -gt 0 ]]; do
    case "$1" in
        --version) version="${2:-}"; shift 2 ;;
        --source) source_dir="$(cd "${2:?}" && pwd -P)"; shift 2 ;;
        --output) mkdir -p "${2:?}"; output_dir="$(cd "${2}" && pwd -P)"; shift 2 ;;
        -h|--help) sed -n '2,9p' "$0"; exit 0 ;;
        *) fail "Unknown argument: $1" ;;
    esac
done

[[ -f "${source_dir}/RELEASE" && -f "${source_dir}/public/index.php" && -f "${source_dir}/app/bootstrap.php" ]] || fail "Not a php81 runtime: ${source_dir}"
marker="$(tr -d '\r\n' < "${source_dir}/RELEASE")"
version="${version:-${marker}}"
[[ "${version}" =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}\.[0-9]{2}$ ]] || fail "Version must look like YYYY.MM.DD.NN (got '${version}')."
[[ "${marker}" == "${version}" ]] || fail "RELEASE marker '${marker}' does not match package version '${version}'."
[[ ! -f "${source_dir}/config/e-kabineti.php" ]] || fail "Refusing to package a private configuration: ${source_dir}/config/e-kabineti.php"
command -v sha256sum >/dev/null || fail 'sha256sum is required.'

if command -v php >/dev/null; then
    while IFS= read -r -d '' file; do
        php -l "${file}" >/dev/null || fail "PHP syntax error in ${file}"
    done < <(find "${source_dir}/app" "${source_dir}/bin" "${source_dir}/public" -name '*.php' -print0)
    echo "[OK]   PHP syntax check passed."
fi

release_name="e-kabineti-php81-${version}"
stage="$(mktemp -d "${TMPDIR:-/tmp}/ekabineti-package.XXXXXX")"
trap 'rm -rf -- "${stage}"' EXIT
release_stage="${stage}/${release_name}"
mkdir -p "${release_stage}"

# Copy the runtime, then remove what must never ship.
cp -a "${source_dir}/." "${release_stage}/"
rm -rf -- "${release_stage}/tests" "${release_stage}/storage" "${release_stage}/.env" "${release_stage}/.env.example"
find "${release_stage}" \( -name '.env' -o -name '.env.*' -o -name '*.pem' -o -name '*.key' -o -name '.DS_Store' \) -exec rm -f -- {} +
mkdir -p "${release_stage}/storage/logs" "${release_stage}/storage/private"
: > "${release_stage}/storage/logs/.keep"
: > "${release_stage}/storage/private/.keep"
chmod 0755 "${release_stage}"/bin/*.sh

mkdir -p "${output_dir}"
zip_path="${output_dir}/${release_name}.zip"
rm -f -- "${zip_path}" "${zip_path}.sha256"

# Deterministic entry order; POSIX paths relative to the release root.
if command -v zip >/dev/null; then
    (cd "${release_stage}" && find . -type f -printf '%P\n' | LC_ALL=C sort | zip -q -X -9 "${zip_path}" -@)
elif command -v python3 >/dev/null; then
    python3 -I - "${release_stage}" "${zip_path}" <<'PY'
import os, sys, zipfile
root, target = sys.argv[1], sys.argv[2]
files = sorted(os.path.relpath(os.path.join(d, f), root).replace(os.sep, '/') for d, _, fs in os.walk(root) for f in fs)
with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for name in files:
        archive.write(os.path.join(root, name), name)
PY
else
    fail 'Install zip (apt-get install -y zip) or python3.'
fi

# Verify the archive: no absolute paths, traversal, backslashes or private configuration.
entries="$(unzip -Z1 "${zip_path}")"
[[ -n "${entries}" ]] || fail 'Release ZIP is empty.'
while IFS= read -r entry; do
    [[ "${entry}" != *\\* ]] || fail "Backslash in ZIP entry: ${entry}"
    [[ "${entry}" != /* && ! "${entry}" =~ ^[A-Za-z]: && "/${entry}/" != */../* ]] || fail "Unsafe ZIP entry: ${entry}"
    [[ "${entry}" != config/e-kabineti.php && ! "${entry}" =~ (^|/)\.env($|\.) ]] || fail "Protected configuration in ZIP: ${entry}"
done <<< "${entries}"
for required in RELEASE public/index.php app/bootstrap.php database/schema.sql; do
    grep -qx "${required}" <<< "${entries}" || fail "Package is missing ${required}"
done

(cd "${output_dir}" && sha256sum "${release_name}.zip" > "${release_name}.zip.sha256")
echo "[OK]   ${zip_path} ($(grep -c '' <<< "${entries}") files)"
echo "[OK]   ${zip_path}.sha256: $(cut -d' ' -f1 "${zip_path}.sha256")"
