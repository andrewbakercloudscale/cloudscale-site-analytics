#!/bin/bash
# Build cloudscale-site-analytics.zip from the repo directory
# Creates a zip with cloudscale-site-analytics/ as the top level folder
# which is the structure WordPress expects for plugin upload
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Load shared Claude model config
GITHUB_DIR="$(dirname "$SCRIPT_DIR")"
# shellcheck source=../.claude-config.sh
source "$GITHUB_DIR/.claude-config.sh"
REPO_DIR="$SCRIPT_DIR"
PLUGIN_NAME="cloudscale-site-analytics"
ZIP_FILE="$SCRIPT_DIR/$PLUGIN_NAME.zip"
TEMP_DIR=$(mktemp -d)

echo "Building plugin zip from $REPO_DIR..."
# ── Auto-increment patch version ─────────────────────────────────────────────
MAIN_PHP=$(grep -rl "^ \* Version:" "$REPO_DIR" --include="*.php" 2>/dev/null | grep -v "repo/" | head -1)
if [ -z "$MAIN_PHP" ]; then
  echo "ERROR: Could not find main plugin PHP file with Version header."
  exit 1
fi
CURRENT_VER=$(grep "^ \* Version:" "$MAIN_PHP" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
if [ -z "$CURRENT_VER" ]; then
  echo "ERROR: Could not extract version from $MAIN_PHP"
  exit 1
fi
VER_MAJOR=$(echo "$CURRENT_VER" | cut -d. -f1)
VER_MINOR=$(echo "$CURRENT_VER" | cut -d. -f2)
VER_PATCH=$(echo "$CURRENT_VER" | cut -d. -f3)
NEW_VER="$VER_MAJOR.$VER_MINOR.$((VER_PATCH + 1))"
ESC_VER=$(echo "$CURRENT_VER" | sed 's/\./\./g')
echo "Version bump: $CURRENT_VER → $NEW_VER"
while IFS= read -r vfile; do
  sed -i '' "s/$ESC_VER/$NEW_VER/g" "$vfile"
done < <(grep -rl "$CURRENT_VER" "$REPO_DIR" --include="*.php" --include="*.js" --include="*.txt" 2>/dev/null | grep -v "\.git" | grep -v "/repo/" | grep -v "/node_modules/")
# ─────────────────────────────────────────────────────────────────────────────

# PHP syntax check — abort before packaging if any file has a parse error
echo "Checking PHP syntax..."
LINT_ERRORS=0
while IFS= read -r -d '' phpfile; do
  result=$(php -l "$phpfile" 2>&1)
  if [ $? -ne 0 ]; then
    echo "$result"
    LINT_ERRORS=1
  fi
done < <(find "$REPO_DIR" -name "*.php" \
    ! -path "*/repo/*" ! -path "*/vendor/*" ! -path "*/tests/*" \
    ! -path "*/node_modules/*" ! -path "*/_archive/*" -print0)
if [ "$LINT_ERRORS" -ne 0 ]; then
  echo ""
  echo "ERROR: PHP syntax errors found above. Fix before deploying."
  exit 1
fi
echo "PHP syntax: OK"
echo ""

# PHP runtime include test — catches TypeError/fatal errors that php -l misses.
echo "Checking PHP runtime includes..."
RUNTIME_ERRORS=0
while IFS= read -r -d '' phpfile; do
  basename=$(basename "$phpfile")
  [[ "$basename" == "uninstall.php" ]] && continue
  result=$(php -r "
define('ABSPATH', '/tmp/');
define('WPINC', 'wp-includes');
define('DB_HOST', '');
\$_SERVER['HTTP_HOST'] = 'localhost';
set_error_handler(function(\$errno, \$str) {
    if (\$errno === E_FATAL || \$errno === E_ERROR) { echo \"FATAL: \$str\n\"; exit(1); }
    return true;
});
\$code = file_get_contents('$phpfile');
if (strpos(\$code, 'class ') !== false || strpos(\$code, 'function ') !== false) {
    if (strpos(\$code, 'require') === false && strpos(\$code, 'wp_') === false
        && strpos(\$code, 'add_filter') === false && strpos(\$code, 'add_action') === false) {
        @include '$phpfile';
    }
}
" 2>&1 | grep -i "FATAL\|TypeError\|ParseError" || true)
  if [ -n "$result" ]; then
    echo "  RUNTIME ERROR in $phpfile:"
    echo "    $result"
    RUNTIME_ERRORS=1
  fi
done < <(find "$REPO_DIR" -maxdepth 1 -name "*.php" -print0 2>/dev/null)
if [ "$RUNTIME_ERRORS" -ne 0 ]; then
  echo ""
  echo "ERROR: PHP runtime errors found — crashes on first HTTP request."; exit 1
fi
echo "PHP runtime: OK"
echo ""

# ── Cross-file PHP method existence check ──────────────────────────────────
# Catches ClassName::method() calls where the method is not defined in the
# plugin — passes php -l but causes fatal errors at runtime (e.g. after an
# OPcache serves a stale class that is missing a newly added method).
echo "Checking cross-file method calls..."
XFILE_ERRORS=0
XFILE_PHP=()
while IFS= read -r -d '' f; do
    XFILE_PHP+=("$f")
done < <(find "$REPO_DIR" -name "*.php" \
    ! -path "*/repo/*" ! -path "*/vendor/*" ! -path "*/tests/*" \
    ! -path "*/node_modules/*" ! -path "*/_archive/*" -print0 2>/dev/null)
if [[ ${#XFILE_PHP[@]} -gt 0 ]]; then
    PLUGIN_CLASSES=$(grep -hE "^(abstract |final )?class [A-Z_]" \
        "${XFILE_PHP[@]}" 2>/dev/null | \
        sed -E 's/^(abstract |final )?class ([A-Z_][a-zA-Z_0-9]+).*/\2/' | sort -u)
    while IFS= read -r class; do
        [[ -z "$class" ]] && continue
        while IFS= read -r method; do
            [[ -z "$method" ]] && continue
            if ! grep -qh "function ${method}(" "${XFILE_PHP[@]}" 2>/dev/null; then
                echo "  UNDEFINED: ${class}::${method}() — not found in plugin files"
                XFILE_ERRORS=1
            fi
        done < <(grep -h "${class}::" "${XFILE_PHP[@]}" 2>/dev/null \
            | grep -v '^\s*//' | grep -v '^\s*\*' \
            | grep -oh "${class}::[a-zA-Z_][a-zA-Z_0-9]*(" \
            | cut -d: -f3 | tr -d '(' | sort -u)
    done <<< "$PLUGIN_CLASSES"
fi
if [[ "$XFILE_ERRORS" -ne 0 ]]; then
    echo ""
    echo "ERROR: Undefined method calls found — fix before deploying."
    exit 1
fi
echo "Cross-file methods: OK"
echo ""

# ── PHPCS WordPress standards check ──────────────────────────────────────────
_PHPCS=""
for _candidate in \
    "$REPO_DIR/vendor/bin/phpcs" \
    "$HOME/.composer/vendor/bin/phpcs" \
    "$(command -v phpcs 2>/dev/null || true)"; do
    [ -x "$_candidate" ] && { _PHPCS="$_candidate"; break; }
done

if [ -z "$_PHPCS" ]; then
    echo "phpcs not found — attempting auto-install..."
    if ! command -v composer &>/dev/null && command -v brew &>/dev/null; then
        brew install --quiet composer && hash -r
    fi
    if command -v composer &>/dev/null; then
        composer global require --quiet \
            squizlabs/php_codesniffer \
            wp-coding-standards/wpcs \
            dealerdirect/phpcodesniffer-composer-installer 2>&1 | tail -3
        _PHPCS="$(composer global config home 2>/dev/null)/vendor/bin/phpcs"
    fi
fi

if [ -z "$_PHPCS" ] || [ ! -x "$_PHPCS" ]; then
    echo "ERROR: phpcs not found and could not be installed automatically."
    echo "  Install: composer global require squizlabs/php_codesniffer wp-coding-standards/wpcs dealerdirect/phpcodesniffer-composer-installer"
    exit 1
fi

echo "Running PHPCS (WordPress standard)..."
PHPCS_OUT=$("$_PHPCS" \
    --standard=WordPress \
    --severity=5 \
    --ignore="*/vendor/*,*/node_modules/*,*/lib/*,*/repo/*,*/archive/*" \
    --extensions=php \
    "$REPO_DIR" 2>&1 || true)
echo "$PHPCS_OUT"
echo ""
if echo "$PHPCS_OUT" | grep -q "| ERROR"; then
    echo "WARNING: PHPCS errors found — run phpcbf to auto-fix formatting issues."
fi
echo "PHPCS: OK (no blocking errors)"
echo ""

# Create temp directory with plugin name as wrapper
mkdir -p "$TEMP_DIR/$PLUGIN_NAME"
rsync -a \
  --exclude='.*' \
  --include='phpcs.xml' \
  --exclude='*.zip' --exclude='*.sh' --exclude='*.xml' \
  --exclude='*.json' \
  --exclude='repo/' --exclude='docs/' --exclude='tests/' \
  --exclude='node_modules/' --exclude='svn-assets/' \
  --exclude='playwright-report/' --exclude='playwright.config.js' \
  --exclude='crash-logs/' --exclude='_archive/' --exclude='archive/' \
  --exclude='generate-help-docs.js' \
  --exclude='WORKING-NOTES.md' \
  "$REPO_DIR/" "$TEMP_DIR/$PLUGIN_NAME/"

# Main plugin file (cloudscale-site-analytics.php) already matches the folder
# name WordPress expects — no rename needed.

# ── Deterministic WordPress.org file-write standards guard ───────────────────
# Scans the STAGED plugin (exactly what ships) for disallowed file writes:
# executable code (.php/.sh) deployed at runtime, writes to the plugin dir,
# OS/system paths, or the /wp-content root. See standards-grep-guard.sh.
STD_GUARD="$GITHUB_DIR/standards-grep-guard.sh"
[ -f "$STD_GUARD" ] || STD_GUARD="$(dirname "$GITHUB_DIR")/standards-grep-guard.sh"
if [ -f "$STD_GUARD" ]; then
  bash "$STD_GUARD" "$TEMP_DIR/$PLUGIN_NAME" || { rm -rf "$TEMP_DIR"; exit 1; }
else
  echo "WARNING: standards-grep-guard.sh not found — file-write guard skipped."
fi

# Build zip with correct structure
rm -f "$ZIP_FILE"
cd "$TEMP_DIR"
zip -r "$ZIP_FILE" "$PLUGIN_NAME/"

# Cleanup
rm -rf "$TEMP_DIR"

echo ""
echo "Zip built: $ZIP_FILE"
echo ""
echo "Contents:"
unzip -l "$ZIP_FILE" | head -25
echo ""

# Show version and verify stable tag matches
VERSION=$(grep "CSPV_VERSION" "$REPO_DIR/cloudscale-site-analytics.php" | head -1 | grep -o "'[^']*'" | tail -1 | tr -d "'")
STABLE_TAG=$(grep "^Stable tag:" "$REPO_DIR/readme.txt" | head -1 | sed 's/Stable tag:[[:space:]]*//' | tr -d '[:space:]')
echo "Plugin version: $VERSION"
echo "Stable tag:     $STABLE_TAG"
if [ "$VERSION" != "$STABLE_TAG" ]; then
  echo ""
  echo "ERROR: Version mismatch! Plugin version ($VERSION) != Stable tag ($STABLE_TAG)"
  echo "Update readme.txt Stable tag before deploying."
  exit 1
fi
echo "Version check: OK"
echo ""
echo "To deploy to S3, run:"
  echo "  bash $SCRIPT_DIR/backup-s3.sh"
echo ""
echo "Then on the server:"
echo "  sudo aws s3 cp s3://andrewninjawordpress/cloudscale-site-analytics.zip /tmp/lwfa.zip && sudo rm -rf /var/www/html/wp-content/plugins/cloudscale-site-analytics && sudo unzip -q /tmp/lwfa.zip -d /var/www/html/wp-content/plugins/ && sudo chown -R apache:apache /var/www/html/wp-content/plugins/cloudscale-site-analytics && php -r \"if(function_exists('opcache_reset'))opcache_reset();\""
