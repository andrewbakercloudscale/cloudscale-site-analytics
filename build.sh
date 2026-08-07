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
# Pin the main file rather than taking the first `grep -rl` hit: that ordering is
# filesystem-dependent, and line 41 below already hardcodes this same file, so a
# `head -1` that ever resolved elsewhere would bump two different files' versions.
MAIN_PHP="$REPO_DIR/cloudscale-site-analytics.php"
if [ ! -f "$MAIN_PHP" ]; then
  echo "ERROR: main plugin file not found: $MAIN_PHP"
  exit 1
fi

# Take the HIGHEST of the three version strings as the base, not the header alone.
# The header froze at 2.9.457 while the other two reached 2.9.463; bumping from the
# header would have shipped 2.9.458 — a version LOWER than what is already live,
# which WordPress would refuse to treat as an update. sort -V picks the real
# high-water mark so a drifted tree converges on the next build instead of
# regressing.
HDR_NOW=$(grep -m1 "^ \* Version:" "$MAIN_PHP" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
CSPV_NOW=$(grep -m1 "CSPV_VERSION" "$MAIN_PHP" | grep -o "'[^']*'" | tail -1 | tr -d "'")
TAG_NOW=$(grep -m1 "^Stable tag:" "$REPO_DIR/readme.txt" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
CURRENT_VER=$(printf '%s\n' "$HDR_NOW" "$CSPV_NOW" "$TAG_NOW" | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | tail -1)
if [ -z "$CURRENT_VER" ]; then
  echo "ERROR: Could not extract a version from $MAIN_PHP or readme.txt"
  exit 1
fi
if [ "$HDR_NOW" != "$CSPV_NOW" ] || [ "$CSPV_NOW" != "$TAG_NOW" ]; then
  echo "NOTE: version strings had drifted (header=$HDR_NOW CSPV=$CSPV_NOW tag=$TAG_NOW);"
  echo "      converging all three on the bump from $CURRENT_VER."
fi
VER_MAJOR=$(echo "$CURRENT_VER" | cut -d. -f1)
VER_MINOR=$(echo "$CURRENT_VER" | cut -d. -f2)
VER_PATCH=$(echo "$CURRENT_VER" | cut -d. -f3)
NEW_VER="$VER_MAJOR.$VER_MINOR.$((VER_PATCH + 1))"
ESC_VER=$(printf '%s\n' "$CURRENT_VER" | sed 's/\./\\./g')
echo "Version bump: $CURRENT_VER → $NEW_VER"
# Targeted bump ONLY. The old blanket replace-everywhere sed rewrote EVERY
# occurrence of the previous version — historical @since/@deprecated docblock
# tags and past readme.txt changelog headings included — so release history
# was silently rewritten on every build.
#
# These three seds match on the FIELD, not on the old value. They used to require
# the line to already equal $ESC_VER, which is precisely how the drift became
# permanent: once CSPV_VERSION and Stable tag moved past the header, every
# subsequent bump silently matched nothing and changed nothing, with no error.
# Matching the field keeps the bump targeted (history and @since tags are still
# never touched) while guaranteeing all three converge.
sed -i '' "s/^\( \* Version:[[:space:]]*\)[0-9][0-9.]*[[:space:]]*\$/\1${NEW_VER}/" "$MAIN_PHP"
sed -i '' "s/\(define([[:space:]]*'CSPV_VERSION',[[:space:]]*'\)[0-9][0-9.]*'/\1${NEW_VER}'/" "$MAIN_PHP"
sed -i '' "s/^\(Stable tag:[[:space:]]*\)[0-9][0-9.]*[[:space:]]*\$/\1${NEW_VER}/" "$REPO_DIR/readme.txt"
# Promote ONLY the topmost changelog heading written for the pre-bump version;
# headings for past releases are never dragged forward.
# A new changelog entry is written as "= Unreleased =" and this stamps it with the
# version the build produces. No other heading is ever relabelled.
#
# This used to promote the topmost heading matching the PRE-bump version, assuming
# such a heading could only be a freshly written entry. It cannot tell that apart
# from the previous release's own heading, which is legitimately labelled with that
# version, so every build that added no entry dragged the last release's heading
# forward by one. In the SEO plugin a narration entry travelled 4.21.459 -> .460 ->
# .461 -> .462 that way, and the published changelog credited the current release
# with a change that had shipped three releases earlier.
if grep -q '^= Unreleased =$' "$REPO_DIR/readme.txt"; then
  sed -i '' "1,/^= Unreleased =\$/ s/^= Unreleased =\$/= ${NEW_VER} =/" "$REPO_DIR/readme.txt"
  echo "  readme.txt changelog: promoted '= Unreleased =' to '= ${NEW_VER} ='"
else
  echo "  readme.txt changelog: no '= Unreleased =' entry, headings left untouched"
fi
# JS @version headers.
while IFS= read -r vfile; do
  sed -i '' "s/\(@version[[:space:]]*\)${ESC_VER}\$/\1${NEW_VER}/" "$vfile"
done < <(grep -rl "@version[[:space:]]*$CURRENT_VER" "$REPO_DIR" --include="*.js" 2>/dev/null | grep -v "\.git" | grep -v "/repo/" | grep -v "/node_modules/")
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

# ── WP bootstrap safety check ────────────────────────────────────────────────
# Catches calls to functions that require a bootstrapped WordPress environment
# (user auth, DB, etc.) made at global scope in the main plugin PHP file.
# These pass php -l but silently misbehave or fatal-crash on real page loads —
# e.g. current_user_can() called before wp_set_current_user() always returns false,
# and in some WP versions can trigger a PHP fatal that causes a 503.
echo "Checking WP bootstrap safety..."
BOOTSTRAP_ERRORS=0
_BOOTSTRAP_FORBIDDEN='current_user_can,is_user_logged_in,wp_get_current_user,get_current_user_id,check_admin_referer,check_ajax_referer,is_multisite,switch_to_blog,restore_current_blog'
while IFS= read -r -d '' phpfile; do
    _result=$(php -r "
\$file = '$phpfile';
\$tokens = token_get_all(file_get_contents(\$file));
// Scan only the leading global-scope preamble — tokens before the first
// top-level class/function/interface/trait declaration. Everything after that
// is inside a declaration body and is safe to call WP bootstrap functions.
\$forbidden = explode(',', '$_BOOTSTRAP_FORBIDDEN');
\$errors    = [];
foreach (\$tokens as \$tok) {
    if (!is_array(\$tok)) continue;
    \$type = \$tok[0];
    // Stop scanning at the first top-level declaration.
    if (in_array(\$type, [T_CLASS, T_FUNCTION, T_INTERFACE, T_TRAIT])) break;
    if (\$type === T_STRING && in_array(\$tok[1], \$forbidden)) {
        \$errors[] = 'BOOTSTRAP: ' . basename(\$file) . ':' . \$tok[2]
            . ': ' . \$tok[1] . '() in plugin preamble — requires bootstrapped WP, '
            . 'use a hook (add_action) or check WP_ADMIN/REST_REQUEST/\$_COOKIE instead';
    }
}
foreach (\$errors as \$e) echo \$e . PHP_EOL;
exit(empty(\$errors) ? 0 : 1);
" 2>&1 || true)
    if [ -n "$_result" ]; then
        echo "$_result"
        BOOTSTRAP_ERRORS=1
    fi
done < <(find "$REPO_DIR" -maxdepth 1 -name "*.php" -print0 2>/dev/null)
if [ "$BOOTSTRAP_ERRORS" -ne 0 ]; then
    echo ""
    echo "ERROR: WP bootstrap safety violations — fix before building."
    exit 1
fi
echo "WP bootstrap safety: OK"
echo ""

# ── PHPCS WordPress standards check ──────────────────────────────────────────
_PHPCS=""
for _candidate in \
    "$REPO_DIR/vendor/bin/phpcs" \
    "$HOME/.config/composer/vendor/bin/phpcs" \
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

# ── readme.txt section limits ────────────────────────────────────────────────
# WordPress.org TRUNCATES an over-long readme section instead of rejecting it, so
# the plugin page silently loses content and nothing in the build complains. This
# runs after the version bump above, because that rewrites readme.txt.
#
# Two earlier hand-rolled versions of this check passed while the section was in
# fact being truncated, because they encoded the rule wrongly: the limit is counted
# in WORDS (Plugin Check's "2500 characters" message is misleading), and every
# section wordpress.org does not recognise -- "External services", "Credits" -- is
# folded into other_notes and then added onto DESCRIPTION before trimming. The
# shared script is the single source of truth; do not re-implement the rule here.
_README_CHECK="$GITHUB_DIR/shared-build-tools/check-readme-limits.php"
echo "Checking readme.txt section limits..."
if [ ! -f "$_README_CHECK" ]; then
    # Fail loudly: a silently-missing checker would recreate the exact hole this closes.
    echo "ERROR: readme limit checker not found at $_README_CHECK"
    exit 1
fi
if ! php "$_README_CHECK" "$REPO_DIR/readme.txt"; then
    echo "ERROR: readme.txt would be truncated on WordPress.org (details above)."
    exit 1
fi
echo "readme.txt section limits: OK"
echo ""

# ── Telegram alerts carry local time ────────────────────────────────────────
# Alerts arrive on a phone, at night, read by someone in the site's own timezone —
# and they quoted UTC ("Last heartbeat: 2026-08-05 02:30:01 UTC" during a real
# outage), so the reader had to do arithmetic before judging how old a failure was.
# Stamped centrally in CloudScale_Telegram::send() so a new alert cannot ship
# without one; asserted against THIS plugin's synced copy so drift fails here.
_TG_TIME_CHECK="$GITHUB_DIR/shared-build-tools/check-telegram-local-time.php"
echo "Checking Telegram alerts carry local time..."
if [ ! -f "$_TG_TIME_CHECK" ]; then
    echo "ERROR: telegram local-time checker not found at $_TG_TIME_CHECK"
    exit 1
fi
if ! php "$_TG_TIME_CHECK" "$REPO_DIR"; then
    echo "ERROR: Telegram alerts would go out without local time (details above)."
    exit 1
fi
echo ""

echo "Running PHPCS (WordPress standard)..."
# memory_limit: PHP's 128M default is not enough to tokenise the whole tree — the
# main plugin file alone is several hundred KB and the run dies partway through it.
# Measured need across these plugins is ~256M today; 1024M leaves headroom to grow.
set +e
PHPCS_OUT=$("$_PHPCS" \
    -d memory_limit=1024M \
    --standard="$REPO_DIR/phpcs.xml" \
    --severity=5 \
    --extensions=php \
    -s \
    "$REPO_DIR" 2>&1)
_PHPCS_RC=$?
set -e
echo "$PHPCS_OUT"
echo ""

# A PHPCS run that DIED looks identical to a clean one further down: it emits a
# stack trace with no "| ERROR " lines, and the old `|| true` threw the exit code
# away — so the build announced "0 errors, 0 warnings" having checked only part of
# the tree. That is exactly how a real WPCS error survived every local build and
# was first reported by WordPress.org's Plugin Check.
# Exit codes: 0 = clean, 1/2 = issues found, 3 = processing error, 255 = PHP fatal.
if [ "$_PHPCS_RC" -gt 2 ]; then
    echo "ERROR: PHPCS did not complete (exit ${_PHPCS_RC}). Its output is truncated, NOT clean."
    if echo "$PHPCS_OUT" | grep -qi "out of memory"; then
        echo "  Cause: PHPCS ran out of memory — raise -d memory_limit above in this script."
    fi
    exit 1
fi

# Count violations. grep|wc pipeline always exits 0 — safe under set -e.
_PHPCS_ERRS=$(echo "$PHPCS_OUT" | grep -F "| ERROR " | wc -l | tr -d '[:space:]')
_PHPCS_WARNS=$(echo "$PHPCS_OUT" | grep -F "| WARNING " | wc -l | tr -d '[:space:]')

# Block on any ERROR — WordPress.org reviewers reject plugins with any PHPCS error.
# Rules already suppressed in phpcs.xml will not appear here.
if [ "${_PHPCS_ERRS:-0}" -gt 0 ]; then
    echo "ERROR: PHPCS found ${_PHPCS_ERRS} error(s) — fix before building (WP.org rejects all errors)."
    exit 1
fi

# Block on development/discouraged-function warnings — WP.org explicitly rejects
# var_dump(), error_log(), eval(), base64_decode() etc. even when flagged as warnings.
if echo "$PHPCS_OUT" | grep -qE "WordPress\.PHP\.(DevelopmentFunctions|DiscouragedPHPFunctions)"; then
    echo "ERROR: Development or discouraged PHP functions flagged — remove before WordPress.org submission."
    exit 1
fi

# Non-blocking warning summary — must be zero before WordPress.org submission.
if [ "${_PHPCS_WARNS:-0}" -gt 0 ]; then
    echo "PHPCS: OK — 0 errors, ${_PHPCS_WARNS} warning(s) (must be clean before WP.org submission)"
    echo "  Warning breakdown:"
    echo "$PHPCS_OUT" | grep -oE '\([A-Za-z]+\.[A-Za-z.]+\)' | tr -d '()' | sort | uniq -c | sort -rn | head -8 | sed 's/^/    /'
else
    echo "PHPCS: OK — 0 errors, 0 warnings"
fi
echo ""

# Create temp directory with plugin name as wrapper
mkdir -p "$TEMP_DIR/$PLUGIN_NAME"
rsync -a \
  --exclude='.*' \
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

# ── Verify ALL THREE version strings agree ───────────────────────────────────
# This used to compare CSPV_VERSION against Stable tag and nothing else, so it
# could never see the one version that matters most: the "* Version:" plugin
# header, which is what WordPress itself reads and displays. The header silently
# froze at 2.9.457 on 2026-07-25 while CSPV_VERSION and Stable tag advanced to
# 2.9.463 — six releases where WP reported a version that had not existed for
# weeks, and the check said OK every time. A gate that passes while measuring the
# wrong thing is worse than no gate, so it now asserts all three and says how
# many it compared.
HEADER_VER=$(grep -m1 "^ \* Version:" "$MAIN_PHP" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
VERSION=$(grep "CSPV_VERSION" "$REPO_DIR/cloudscale-site-analytics.php" | head -1 | grep -o "'[^']*'" | tail -1 | tr -d "'")
STABLE_TAG=$(grep "^Stable tag:" "$REPO_DIR/readme.txt" | head -1 | sed 's/Stable tag:[[:space:]]*//' | tr -d '[:space:]')

echo "Plugin header:  ${HEADER_VER:-<missing>}"
echo "CSPV_VERSION:   ${VERSION:-<missing>}"
echo "Stable tag:     ${STABLE_TAG:-<missing>}"

VER_FAIL=0
for pair in "plugin header:${HEADER_VER}" "CSPV_VERSION:${VERSION}" "Stable tag:${STABLE_TAG}"; do
  label="${pair%%:*}"
  value="${pair##*:}"
  if [ -z "$value" ]; then
    echo "ERROR: could not read the $label version."
    VER_FAIL=1
  fi
done
if [ "$VER_FAIL" -eq 0 ] && { [ "$HEADER_VER" != "$VERSION" ] || [ "$VERSION" != "$STABLE_TAG" ]; }; then
  echo ""
  echo "ERROR: Version mismatch — these three must be identical:"
  echo "  plugin header  = $HEADER_VER   (what WordPress reports)"
  echo "  CSPV_VERSION   = $VERSION      (what the code uses for cache busting)"
  echo "  Stable tag     = $STABLE_TAG   (what WordPress.org publishes)"
  VER_FAIL=1
fi
if [ "$VER_FAIL" -ne 0 ]; then
  exit 1
fi
echo "Version check: OK (3 version strings compared, all $HEADER_VER)"
echo ""
echo "To deploy to S3, run:"
  echo "  bash $SCRIPT_DIR/backup-s3.sh"
echo ""
echo "Then on the server:"
echo "  sudo aws s3 cp s3://andrewninjawordpress/cloudscale-site-analytics.zip /tmp/lwfa.zip && sudo rm -rf /var/www/html/wp-content/plugins/cloudscale-site-analytics && sudo unzip -q /tmp/lwfa.zip -d /var/www/html/wp-content/plugins/ && sudo chown -R apache:apache /var/www/html/wp-content/plugins/cloudscale-site-analytics && php -r \"if(function_exists('opcache_reset'))opcache_reset();\""
