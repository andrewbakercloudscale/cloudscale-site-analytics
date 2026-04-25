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
echo "Restarting container to flush PHP-FPM opcache..."
pi_ssh "docker restart ${CONTAINER} && echo 'Container restarted OK'"

echo ""
echo "Clearing PHP opcache (post-restart)..."
sleep 3
pi_ssh "docker exec ${CONTAINER} php -r 'opcache_reset();' 2>/dev/null && echo 'opcache cleared' || echo 'opcache not available'"

echo ""
echo "Checking site health after deploy..."
HTTP_STATUS=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 "$SITE_URL/")
if [ "$HTTP_STATUS" != "200" ]; then
    echo ""
    echo "ERROR: Site returned HTTP $HTTP_STATUS after deploy — auto-rolling back!"
    pi_ssh "
        if [ -d /tmp/${PLUGIN_NAME}-rollback ]; then
            docker exec ${CONTAINER} rm -rf ${PLUGIN_DIR}/${PLUGIN_NAME} && \
            docker cp /tmp/${PLUGIN_NAME}-rollback ${CONTAINER}:${PLUGIN_DIR}/${PLUGIN_NAME} && \
            docker exec ${CONTAINER} chown -R www-data:www-data ${PLUGIN_DIR}/${PLUGIN_NAME} && \
            docker exec ${CONTAINER} bash -c 'kill -USR2 1 2>/dev/null || true' && \
            echo 'Auto-rolled back to previous version.'
        else
            echo 'ERROR: No rollback backup available!'
        fi
    "
    exit 1
fi
echo "Site health: OK (HTTP $HTTP_STATUS)"
