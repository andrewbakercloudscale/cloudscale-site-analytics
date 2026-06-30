#!/bin/bash
# Deploy cloudscale-site-analytics to ALL sites that run this plugin.
#
# Sites:
#   1. pi_wordpress  — andrewbaker.ninja
#   2. cs_wordpress  — help.cloudscale.consulting
#
# Usage: bash deploy-all-sites.sh
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
export _DEPLOY_ALL_SITES=1  # Suppresses the per-deploy reminder banner

echo "╔═════════════════════════════════════════════════════════════════╗"
echo "║  Deploying cloudscale-site-analytics to ALL sites              ║"
echo "╚═════════════════════════════════════════════════════════════════╝"

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Site 1/2 — pi_wordpress (andrewbaker.ninja)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
bash "$SCRIPT_DIR/deploy-wordpress.sh"

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Site 2/2 — cs_wordpress (help.cloudscale.consulting)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
PI_CONTAINER=cs_wordpress PI_SITE_URL=https://help.cloudscale.consulting \
    bash "$SCRIPT_DIR/deploy-wordpress.sh"

echo ""
echo "╔═════════════════════════════════════════════════════════════════╗"
echo "║  All sites deployed successfully.                              ║"
echo "╚═════════════════════════════════════════════════════════════════╝"
