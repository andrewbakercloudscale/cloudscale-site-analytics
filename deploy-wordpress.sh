#!/bin/bash
set -e

PI_KEY="/Users/cp363412/Desktop/github/pi-monitor/deploy/pi_key"
CONTAINER="pi_wordpress"
WP_PATH="/var/www/html"
SITE_URL="https://andrewbaker.ninja"
PLUGIN_DIR="${WP_PATH}/wp-content/plugins"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_NAME="cloudscale-wordpress-free-analytics"
ZIP="$SCRIPT_DIR/$PLUGIN_NAME.zip"

# ── Pi SSH: try LAN first, fall back to Cloudflare tunnel ────────────────────
_PI_LAN="andrew-pi-5.local"
_PI_CF_HOST="ssh.andrewbaker.ninja"
_PI_CF_USER="andrew.j.baker.007"
_PI_CF_KEY="${HOME}/.cloudflared/ssh.andrewbaker.ninja-cf_key"

if ssh -i "${PI_KEY}" -o StrictHostKeyChecking=no -o ConnectTimeout=4 -o BatchMode=yes \
       "pi@${_PI_LAN}" "exit" 2>/dev/null; then
    echo "Network: home — direct SSH"
    PI_HOST="${_PI_LAN}"; PI_USER="pi"
    SSH_OPTS=(-i "${PI_KEY}" -o StrictHostKeyChecking=no -o ServerAliveInterval=15 -o ServerAliveCountMax=10)
else
    echo "Network: remote — Cloudflare tunnel"
    PI_HOST="${_PI_CF_HOST}"; PI_USER="pi"
    SSH_OPTS=(-i "${HOME}/.cloudflared/pi-service-key" \
              -o "ProxyCommand=${HOME}/.cloudflared/cf-ssh-proxy.sh" \
              -o StrictHostKeyChecking=no -o ServerAliveInterval=15 -o ServerAliveCountMax=10)
fi

pi_ssh() { ssh "${SSH_OPTS[@]}" "${PI_USER}@${PI_HOST}" "$@"; }
pi_scp() { scp "${SSH_OPTS[@]}" "$@"; }

echo "Building zip..."
cd "$SCRIPT_DIR"
bash build.sh

echo ""
echo "Archiving deployment zip..."
mkdir -p "$SCRIPT_DIR/archive"
DEPLOY_STAMP=$(date +%Y-%m-%d-%H%M%S)
cp "$ZIP" "$SCRIPT_DIR/archive/${PLUGIN_NAME}-${DEPLOY_STAMP}.zip"
echo "Archived: $SCRIPT_DIR/archive/${PLUGIN_NAME}-${DEPLOY_STAMP}.zip"

echo ""
echo "Backing up current version on server..."
pi_ssh \
    "docker cp ${CONTAINER}:${PLUGIN_DIR}/${PLUGIN_NAME} /tmp/${PLUGIN_NAME}-rollback 2>/dev/null \
     && echo 'Backup saved to /tmp/${PLUGIN_NAME}-rollback' \
     || echo 'No existing plugin to backup'"

echo ""
echo "Copying zip to server..."
pi_scp "${ZIP}" "${PI_USER}@${PI_HOST}:/tmp/${PLUGIN_NAME}.zip"

echo ""
echo "Installing on server (atomic swap)..."
pi_ssh "
    docker cp /tmp/${PLUGIN_NAME}.zip ${CONTAINER}:/tmp/${PLUGIN_NAME}.zip && \
    docker exec ${CONTAINER} bash -c '
        unzip -q /tmp/${PLUGIN_NAME}.zip -d /tmp/${PLUGIN_NAME}-new/ &&
        rm -rf /tmp/${PLUGIN_NAME}-old &&
        mv ${PLUGIN_DIR}/${PLUGIN_NAME} /tmp/${PLUGIN_NAME}-old 2>/dev/null || true &&
        mv /tmp/${PLUGIN_NAME}-new/${PLUGIN_NAME} ${PLUGIN_DIR}/${PLUGIN_NAME} &&
        chown -R www-data:www-data ${PLUGIN_DIR}/${PLUGIN_NAME} &&
        rm -rf /tmp/${PLUGIN_NAME}-old /tmp/${PLUGIN_NAME}-new /tmp/${PLUGIN_NAME}.zip &&
        kill -SIGHUP 1 2>/dev/null || true &&
        echo \"\" &&
        echo \"Deployed ${PLUGIN_NAME} successfully.\" &&
        grep -i \"Version:\" ${PLUGIN_DIR}/${PLUGIN_NAME}/${PLUGIN_NAME}.php | head -1
    ' && \
    rm -f /tmp/${PLUGIN_NAME}.zip
"

echo ""
echo "Flushing OPcache..."
# Small pause to let filesystem sync before hitting the AJAX endpoint.
sleep 2
# Preferred: flush via AJAX endpoint — PHP-FPM keeps running, zero downtime.
OPCACHE_TOKEN=$(pi_ssh "docker exec ${CONTAINER} php ${WP_PATH}/wp-cli.phar --allow-root option get csdt_opcache_token 2>/dev/null" 2>/dev/null | tr -d '[:space:]' || echo "")
OPCACHE_FLUSHED=0
if [[ -n "$OPCACHE_TOKEN" ]]; then
    FLUSH_RESP=$(curl -sk -X POST --max-time 12 \
        "${SITE_URL}/wp-admin/admin-ajax.php" \
        -d "action=csdt_opcache_flush&token=${OPCACHE_TOKEN}" 2>/dev/null || echo "")
    if echo "$FLUSH_RESP" | grep -q '"flushed":true'; then
        echo "  OPcache flushed via AJAX endpoint — no PHP-FPM restart needed."
        OPCACHE_FLUSHED=1
    else
        echo "  AJAX flush failed — falling back to PHP-FPM graceful reload."
    fi
fi
if [[ "$OPCACHE_FLUSHED" == "0" ]]; then
    echo "  Sending PHP-FPM graceful reload (SIGUSR2)..."
    pi_ssh "docker exec ${CONTAINER} kill -USR2 1 2>/dev/null || true"
    echo "  Waiting 20 s for PHP-FPM workers to fully respawn on the Pi..."
    sleep 20
    echo "  Reloading nginx to clear upstream error state..."
    pi_ssh "docker exec pi_nginx nginx -s reload 2>/dev/null || true"
    sleep 3
    echo "  PHP-FPM reloaded."
fi

echo ""
echo "Checking site health after deploy..."
PHP_OK=0
for attempt in 1 2 3 4 5; do
    HTTP_STATUS=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 \
        -X POST "${SITE_URL}/wp-admin/admin-ajax.php" -d "action=heartbeat" 2>/dev/null)
    if [[ "$HTTP_STATUS" == "200" || "$HTTP_STATUS" == "400" ]]; then
        PHP_OK=1; break
    fi
    echo "  Attempt ${attempt}/5: PHP returned HTTP ${HTTP_STATUS} — retrying in 8s…"
    sleep 8
done
if [[ "$PHP_OK" != "1" ]]; then
    echo ""
    echo "ERROR: PHP-FPM not responding (HTTP $HTTP_STATUS) — auto-rolling back!"
    pi_ssh "
        if [ -d /tmp/${PLUGIN_NAME}-rollback ]; then
            docker exec ${CONTAINER} rm -rf ${PLUGIN_DIR}/${PLUGIN_NAME} && \
            docker cp /tmp/${PLUGIN_NAME}-rollback ${CONTAINER}:${PLUGIN_DIR}/${PLUGIN_NAME} && \
            docker exec ${CONTAINER} chown -R www-data:www-data ${PLUGIN_DIR}/${PLUGIN_NAME} && \
            echo 'Auto-rolled back to previous version.'
        else
            echo 'ERROR: No rollback backup available!'
        fi
    "
    exit 1
fi
echo "Site health: OK (PHP responding, HTTP $HTTP_STATUS)"


echo ""
echo "Purging Cloudflare cache..."
bash "$SCRIPT_DIR/purge-cloudflare.sh"