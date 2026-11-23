#!/usr/bin/env bash
#
# One-command quality gate for the plugin. Run it before every pull request.
#
#   scripts/check.sh            # all checks
#   scripts/check.sh --no-build # skip the npm asset build (faster)
#
# Checks: PHP lint, PHPCS (security, database, i18n, PHP 8.0-8.5 compatibility),
# PHPStan level 5 against phpstan-baseline.neon, npm asset build (the committed
# dist bundles must match their sources), version consistency and the plugin-root
# allow-list (build/build.py --check).
#
# Analysis tools are not installed through this plugin's composer.json, so they
# never touch the shipped vendor/ directory. Point INFY_TOOLS_DIR at a Composer
# project that requires phpstan/phpstan, phpstan/extension-installer,
# szepeviktor/phpstan-wordpress, php-stubs/wordpress-stubs, squizlabs/php_codesniffer,
# wp-coding-standards/wpcs and phpcompatibility/phpcompatibility-wp. If it is unset,
# the script looks for a "tools" directory with vendor/bin/phpstan in the parent
# directories of the plugin. Works from any git worktree.
#
# Exit code: 0 when every check passes, 1 otherwise. Prints details for failures only.

set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

RUN_BUILD=true
for arg in "$@"; do
    case "$arg" in
        --no-build) RUN_BUILD=false ;;
        -h|--help) sed -n '2,21p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $arg (use --help)"; exit 2 ;;
    esac
done

find_tools_dir() {
    if [ -n "${INFY_TOOLS_DIR:-}" ]; then
        echo "$INFY_TOOLS_DIR"
        return
    fi
    local dir="$ROOT"
    while [ "$dir" != "/" ]; do
        dir="$(dirname "$dir")"
        if [ -x "$dir/tools/vendor/bin/phpstan" ]; then
            echo "$dir/tools"
            return
        fi
    done
}

TOOLS_DIR="$(find_tools_dir)"
FAILED=()
OUT="$(mktemp)"
trap 'rm -f "$OUT"' EXIT

# step NAME COMMAND...: runs the command, prints one status line, details on failure.
step() {
    local name="$1"
    shift
    if "$@" >"$OUT" 2>&1; then
        printf '  ok    %s\n' "$name"
    else
        printf '  FAIL  %s\n' "$name"
        sed 's/^/        /' "$OUT" | head -60
        FAILED+=("$name")
    fi
}

php_lint() {
    local errors
    errors="$(find . -name '*.php' \
        -not -path './vendor/*' -not -path './node_modules/*' \
        -not -path './build/*' -not -path './docs/*' -not -path './changelog/*' -print0 |
        xargs -0 -n 20 -P 8 php -l 2>&1 | grep -v '^No syntax errors detected' || true)"
    if [ -n "$errors" ]; then
        echo "$errors"
        return 1
    fi
}

phpcs_check() {
    "$TOOLS_DIR/vendor/bin/phpcs" -q --standard="$ROOT/phpcs.xml.dist" --report=emacs
}

phpstan_check() {
    "$TOOLS_DIR/vendor/bin/phpstan" analyse -c "$ROOT/phpstan.neon.dist" \
        --no-progress --memory-limit=2G --error-format=raw
}

asset_build() {
    if [ ! -d node_modules ]; then
        echo "node_modules missing: run 'npm ci' first."
        return 1
    fi
    npm run --silent build >/dev/null || { echo "npm run build failed; run it directly for the log."; return 1; }
    if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        local changed
        changed="$(git status --porcelain -- assets/css/dist assets/js/dist)"
        if [ -n "$changed" ]; then
            echo "Built bundles differ from the committed ones; commit the rebuilt files:"
            echo "$changed"
            return 1
        fi
    fi
}

build_check() {
    python3 build/build.py --check
}

echo "check.sh: $ROOT"
if [ -z "$TOOLS_DIR" ] || [ ! -x "$TOOLS_DIR/vendor/bin/phpstan" ] || [ ! -x "$TOOLS_DIR/vendor/bin/phpcs" ]; then
    echo "  FAIL  tools: set INFY_TOOLS_DIR to a directory with vendor/bin/phpcs and vendor/bin/phpstan"
    exit 1
fi

step "PHP lint ($(php -r 'echo PHP_VERSION;'))" php_lint
step "PHPCS (security, DB, i18n, PHPCompatibilityWP 8.0-8.5)" phpcs_check
step "PHPStan level 5 + baseline" phpstan_check
if [ "$RUN_BUILD" = true ]; then
    step "npm run build (dist bundles match sources)" asset_build
else
    printf '  skip  npm run build\n'
fi
step "versions in sync, plugin root allow-list" build_check

if [ "${#FAILED[@]}" -gt 0 ]; then
    echo "check.sh: ${#FAILED[@]} check(s) failed: ${FAILED[*]}"
    exit 1
fi
echo "check.sh: all checks passed"
