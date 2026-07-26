#!/usr/bin/env bash
set -euo pipefail

require_env() {
    local name="$1"
    if [ -z "${!name:-}" ]; then
        echo "Variable requise manquante: ${name}" >&2
        exit 1
    fi
}

require_env CRM_DEPLOY_HOST
require_env CRM_DEPLOY_USER

if [ -z "${CRM_DEPLOY_ROOT:-}" ]; then
    require_env CRM_DEPLOY_PATH
    CRM_DEPLOY_ROOT="$CRM_DEPLOY_PATH"
fi

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"
export COPYFILE_DISABLE=1

if [ "${CRM_DEPLOY_ALLOW_DIRTY:-0}" != "1" ] && [ -n "$(git status --porcelain)" ]; then
    echo "Le depot contient des changements non commites. Utilisez CRM_DEPLOY_ALLOW_DIRTY=1 pour forcer." >&2
    exit 1
fi

CRM_DEPLOY_PORT="${CRM_DEPLOY_PORT:-22}"
CRM_DEPLOY_TMP_DIR="${CRM_DEPLOY_TMP_DIR:-/home/${CRM_DEPLOY_USER}}"
CRM_DEPLOY_COMPOSER="${CRM_DEPLOY_COMPOSER:-composer}"
CRM_DEPLOY_BUILD="${CRM_DEPLOY_BUILD:-1}"
CRM_DEPLOY_KEEP_RELEASES="${CRM_DEPLOY_KEEP_RELEASES:-3}"
CRM_DEPLOY_HEALTH_PATH="${CRM_DEPLOY_HEALTH_PATH:-/up}"
CRM_DEPLOY_HEALTH_URL="${CRM_DEPLOY_HEALTH_URL:-}"
CRM_DEPLOY_HEALTH_RETRIES="${CRM_DEPLOY_HEALTH_RETRIES:-6}"
CRM_DEPLOY_HEALTH_SLEEP="${CRM_DEPLOY_HEALTH_SLEEP:-5}"
CRM_DEPLOY_SKIP_HEALTHCHECK="${CRM_DEPLOY_SKIP_HEALTHCHECK:-0}"
REVISION="$(git rev-parse --short HEAD)"
TIMESTAMP="$(date +%Y%m%d%H%M%S)"
RELEASE_NAME="${TIMESTAMP}-${REVISION}"
ARCHIVE_NAME="crm-release-${RELEASE_NAME}.tgz"
LOCAL_ARCHIVE="$(mktemp -t "${ARCHIVE_NAME}.XXXXXX")"
LOCAL_ARCHIVE_TAR="$(mktemp -t "${ARCHIVE_NAME}.tar.XXXXXX")"
REMOTE_ARCHIVE="${CRM_DEPLOY_TMP_DIR}/${ARCHIVE_NAME}"

cleanup() {
    rm -f "$LOCAL_ARCHIVE" "$LOCAL_ARCHIVE_TAR"
}
trap cleanup EXIT

if [ "$CRM_DEPLOY_BUILD" != "0" ]; then
    npm run build
fi

ssh -p "$CRM_DEPLOY_PORT" "${CRM_DEPLOY_USER}@${CRM_DEPLOY_HOST}" "mkdir -p '${CRM_DEPLOY_TMP_DIR}'"

if [ "${CRM_DEPLOY_ALLOW_DIRTY:-0}" = "1" ]; then
    tar -czf "$LOCAL_ARCHIVE" \
        --exclude='.git' \
        --exclude='.env' \
        --exclude='node_modules' \
        --exclude='vendor' \
        --exclude='storage/app/private' \
        --exclude='storage/app/public' \
        --exclude='storage/framework/cache' \
        --exclude='storage/framework/sessions' \
        --exclude='storage/framework/testing' \
        --exclude='storage/framework/views' \
        --exclude='storage/logs' \
        --exclude='storage/pail' \
        --exclude='storage/redis' \
        --exclude='test-results' \
        --exclude='playwright-report' \
        .
else
    git archive --format=tar --output="$LOCAL_ARCHIVE_TAR" HEAD

    if [ -d public/build ]; then
        tar -rf "$LOCAL_ARCHIVE_TAR" public/build
    fi

    gzip -c "$LOCAL_ARCHIVE_TAR" > "$LOCAL_ARCHIVE"
fi

scp -P "$CRM_DEPLOY_PORT" "$LOCAL_ARCHIVE" "${CRM_DEPLOY_USER}@${CRM_DEPLOY_HOST}:${REMOTE_ARCHIVE}"

ssh -p "$CRM_DEPLOY_PORT" "${CRM_DEPLOY_USER}@${CRM_DEPLOY_HOST}" \
    "CRM_DEPLOY_ROOT='${CRM_DEPLOY_ROOT}' REMOTE_ARCHIVE='${REMOTE_ARCHIVE}' REVISION='${REVISION}' RELEASE_NAME='${RELEASE_NAME}' CRM_DEPLOY_COMPOSER='${CRM_DEPLOY_COMPOSER}' CRM_DEPLOY_KEEP_RELEASES='${CRM_DEPLOY_KEEP_RELEASES}' CRM_DEPLOY_HEALTH_URL='${CRM_DEPLOY_HEALTH_URL}' CRM_DEPLOY_HEALTH_PATH='${CRM_DEPLOY_HEALTH_PATH}' CRM_DEPLOY_HEALTH_RETRIES='${CRM_DEPLOY_HEALTH_RETRIES}' CRM_DEPLOY_HEALTH_SLEEP='${CRM_DEPLOY_HEALTH_SLEEP}' CRM_DEPLOY_SKIP_HEALTHCHECK='${CRM_DEPLOY_SKIP_HEALTHCHECK}' bash -s" <<'REMOTE'
set -euo pipefail
export COPYFILE_DISABLE=1

RELEASES_DIR="${CRM_DEPLOY_ROOT}/releases"
SHARED_DIR="${CRM_DEPLOY_ROOT}/shared"
CURRENT_LINK="${CRM_DEPLOY_ROOT}/current"
RELEASE_DIR="${RELEASES_DIR}/${RELEASE_NAME}"
NEXT_LINK="${CRM_DEPLOY_ROOT}/.current-next"
PREVIOUS_TARGET=""

if [ -L "$CURRENT_LINK" ]; then
    PREVIOUS_TARGET="$(readlink "$CURRENT_LINK")"
fi

rollback_current() {
    if [ -n "$PREVIOUS_TARGET" ] && [ -d "$PREVIOUS_TARGET" ]; then
        rm -f "$NEXT_LINK"
        ln -s "$PREVIOUS_TARGET" "$NEXT_LINK"
        mv -Tf "$NEXT_LINK" "$CURRENT_LINK"
        echo "Rollback automatique vers: ${PREVIOUS_TARGET}" >&2
    fi
}

fail_after_switch() {
    local message="$1"
    echo "$message" >&2
    rollback_current
    exit 1
}

health_url_from_env() {
    if [ -n "$CRM_DEPLOY_HEALTH_URL" ]; then
        printf '%s\n' "$CRM_DEPLOY_HEALTH_URL"
        return
    fi

    if [ ! -f "${SHARED_DIR}/.env" ]; then
        return
    fi

    local app_url
    app_url="$(grep -E '^APP_URL=' "${SHARED_DIR}/.env" | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//")"

    if [ -n "$app_url" ]; then
        printf '%s%s\n' "${app_url%/}" "$CRM_DEPLOY_HEALTH_PATH"
    fi
}

ensure_shared_paths() {
    mkdir -p "$RELEASES_DIR" "$SHARED_DIR"

    if [ ! -f "${SHARED_DIR}/.env" ]; then
        if [ -f "${CRM_DEPLOY_ROOT}/.env" ]; then
            cp "${CRM_DEPLOY_ROOT}/.env" "${SHARED_DIR}/.env"
        else
            echo "Fichier shared/.env absent. Creez ${SHARED_DIR}/.env avant le deploiement." >&2
            exit 1
        fi
    fi

    if [ ! -d "${SHARED_DIR}/storage" ]; then
        if [ -d "${CRM_DEPLOY_ROOT}/storage" ] && [ ! -L "${CRM_DEPLOY_ROOT}/storage" ]; then
            (cd "$CRM_DEPLOY_ROOT" && tar \
                --exclude='storage/framework/cache' \
                --exclude='storage/framework/sessions' \
                --exclude='storage/framework/testing' \
                --exclude='storage/framework/views' \
                --exclude='storage/logs' \
                --exclude='storage/pail' \
                --exclude='storage/redis' \
                -cf - storage) | (cd "$SHARED_DIR" && tar -xf -)
        else
            mkdir -p "${SHARED_DIR}/storage"
        fi
    fi

    mkdir -p \
        "${SHARED_DIR}/storage/app/private" \
        "${SHARED_DIR}/storage/app/public" \
        "${SHARED_DIR}/storage/framework/cache" \
        "${SHARED_DIR}/storage/framework/cache/data" \
        "${SHARED_DIR}/storage/framework/sessions" \
        "${SHARED_DIR}/storage/framework/testing" \
        "${SHARED_DIR}/storage/framework/views" \
        "${SHARED_DIR}/storage/logs"

    mkdir -p "${SHARED_DIR}/public/assets"

    if [ ! -d "${SHARED_DIR}/public/assets/uploads" ]; then
        if [ -d "${CRM_DEPLOY_ROOT}/public/assets/uploads" ] && [ ! -L "${CRM_DEPLOY_ROOT}/public/assets/uploads" ]; then
            cp -a "${CRM_DEPLOY_ROOT}/public/assets/uploads" "${SHARED_DIR}/public/assets/uploads"
        else
            mkdir -p "${SHARED_DIR}/public/assets/uploads"
        fi
    fi
}

switch_current() {
    if [ -e "$CURRENT_LINK" ] && [ ! -L "$CURRENT_LINK" ]; then
        echo "${CURRENT_LINK} existe mais n'est pas un symlink. Deplacez l'ancien dossier ou configurez le document root vers current/public." >&2
        exit 1
    fi

    rm -f "$NEXT_LINK"
    ln -s "$RELEASE_DIR" "$NEXT_LINK"
    mv -Tf "$NEXT_LINK" "$CURRENT_LINK"
}

link_release_public_uploads() {
    mkdir -p "${RELEASE_DIR}/public/assets"
    rm -rf "${RELEASE_DIR}/public/assets/uploads"
    ln -s "${SHARED_DIR}/public/assets/uploads" "${RELEASE_DIR}/public/assets/uploads"
}

switch_public_docroot() {
    local public_path="${CRM_DEPLOY_ROOT}/public"
    local desired_target="${CURRENT_LINK}/public"

    if [ -L "$public_path" ]; then
        if [ "$(readlink "$public_path")" != "$desired_target" ]; then
            rm -f "$public_path"
            ln -s "$desired_target" "$public_path"
        fi

        return
    fi

    if [ -e "$public_path" ]; then
        local backup_path="${CRM_DEPLOY_ROOT}/public.pre-atomic-${RELEASE_NAME}"
        mv "$public_path" "$backup_path"
        echo "Ancien document root public sauvegarde: ${backup_path}"
    fi

    ln -s "$desired_target" "$public_path"
}

run_healthcheck() {
    if [ "$CRM_DEPLOY_SKIP_HEALTHCHECK" = "1" ]; then
        echo "Healthcheck HTTP ignore par CRM_DEPLOY_SKIP_HEALTHCHECK=1."
        return
    fi

    local health_url
    health_url="$(health_url_from_env || true)"

    if [ -z "$health_url" ]; then
        fail_after_switch "Impossible de determiner l'URL de verification. Renseignez CRM_DEPLOY_HEALTH_URL ou APP_URL."
    fi

    if ! command -v curl >/dev/null 2>&1; then
        fail_after_switch "curl est requis sur le serveur pour verifier ${health_url}."
    fi

    local attempt
    for attempt in $(seq 1 "$CRM_DEPLOY_HEALTH_RETRIES"); do
        if curl -fsS --max-time 10 "$health_url" >/dev/null; then
            echo "Healthcheck OK: ${health_url}"
            return
        fi

        if [ "$attempt" -lt "$CRM_DEPLOY_HEALTH_RETRIES" ]; then
            sleep "$CRM_DEPLOY_HEALTH_SLEEP"
        fi
    done

    fail_after_switch "Healthcheck echec: ${health_url}"
}

cleanup_old_releases() {
    local current_target
    current_target="$(readlink "$CURRENT_LINK" || true)"

    local count=0
    find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d | sort -r | while IFS= read -r release; do
        count=$((count + 1))

        if [ "$count" -le "$CRM_DEPLOY_KEEP_RELEASES" ]; then
            continue
        fi

        if [ "$release" = "$current_target" ]; then
            continue
        fi

        rm -rf "$release"
    done
}

ensure_shared_paths

rm -rf "$RELEASE_DIR"
mkdir -p "$RELEASE_DIR"
tar -xzf "$REMOTE_ARCHIVE" -C "$RELEASE_DIR"
rm -f "$REMOTE_ARCHIVE"

if [ ! -f "${RELEASE_DIR}/public/build/manifest.json" ]; then
    echo "Manifest Vite absent: ${RELEASE_DIR}/public/build/manifest.json" >&2
    exit 1
fi

rm -f "${RELEASE_DIR}/.env"
ln -s "${SHARED_DIR}/.env" "${RELEASE_DIR}/.env"
rm -rf "${RELEASE_DIR}/storage"
ln -s "${SHARED_DIR}/storage" "${RELEASE_DIR}/storage"
link_release_public_uploads
mkdir -p "${RELEASE_DIR}/bootstrap/cache"
printf '%s\n' "$REVISION" > "${RELEASE_DIR}/.deployed-revision"

if grep -q '^CRM_ASSET_VERSION=' "${SHARED_DIR}/.env"; then
    sed -i.bak "s/^CRM_ASSET_VERSION=.*/CRM_ASSET_VERSION=${REVISION}/" "${SHARED_DIR}/.env"
    rm -f "${SHARED_DIR}/.env.bak"
else
    printf '\nCRM_ASSET_VERSION=%s\n' "$REVISION" >> "${SHARED_DIR}/.env"
fi

cd "$RELEASE_DIR"
"$CRM_DEPLOY_COMPOSER" install --no-dev --optimize-autoloader --no-interaction
php artisan optimize:clear
php artisan migrate --force
php artisan storage:link --force
php artisan hub:publish-static-assets --force --clean
php artisan hub:publish-module-assets --force
php artisan optimize
php artisan view:cache
php artisan route:list --path=up >/dev/null

switch_current
switch_public_docroot
run_healthcheck

php artisan horizon:terminate || true
php artisan queue:restart || true
cleanup_old_releases

echo "Deploiement atomique termine: ${REVISION}"
echo "Release active: ${RELEASE_DIR}"
echo "Symlink current: ${CURRENT_LINK}"
REMOTE
