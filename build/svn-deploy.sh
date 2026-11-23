#!/bin/bash
#
# WordPress.org SVN Deploy Script
#
# Uses the zip from build.py as the source of truth for trunk/.
# Handles assets (banners, screenshots, icon) separately.
#
# Usage:
#   ./svn-deploy.sh                        # Build + prepare everything (no commit)
#   ./svn-deploy.sh --commit               # Build + prepare + commit
#   ./svn-deploy.sh --assets-only          # Only update assets
#   ./svn-deploy.sh --plugin-only          # Only update trunk from build zip
#   ./svn-deploy.sh --zip=path/to/file.zip # Use existing zip (skip build.py)
#   ./svn-deploy.sh --tag=1.3.0            # Also create a tagged release
#   ./svn-deploy.sh --message="v1.3.0"     # Custom commit message
#   ./svn-deploy.sh --check                # Only check the SVN working copy; exits 1 on
#                                          # unversioned (?), ignored (I), missing (!),
#                                          # obstructed (~) or conflicted (C) entries
#

set -e

# ============================================================
# Configuration
# ============================================================
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SVN_URL="https://plugins.svn.wordpress.org/infy-ai-image-seo-optimizer"
PLUGIN_SLUG="infy-ai-image-seo-optimizer"
# Local paths; override via environment if your layout differs.
SVN_DIR="${SVN_DIR:-$HOME/svn-$PLUGIN_SLUG}"
PLUGIN_DIR="${PLUGIN_DIR:-$(dirname "$SCRIPT_DIR")}"
BANNER_DIR="${BANNER_DIR:-$HOME/ai-image-seo/banner/png}"
BUILD_SCRIPT="$SCRIPT_DIR/build.py"

# Load credentials from .env
SVN_USER=""
SVN_PASS=""
if [ -f "$SCRIPT_DIR/.env" ]; then
    SVN_USER=$(grep -E '^SVN_USERNAME=' "$SCRIPT_DIR/.env" | cut -d'=' -f2- | tr -d '[:space:]')
    SVN_PASS=$(grep -E '^SVN_PASSWORD=' "$SCRIPT_DIR/.env" | cut -d'=' -f2- | tr -d '[:space:]')
fi

# Parse arguments
COMMIT=false
CHECK_ONLY=false
ASSETS_ONLY=false
PLUGIN_ONLY=false
TAG_VERSION=""
ZIP_FILE=""
COMMIT_MSG="Update plugin and assets"

for arg in "$@"; do
    case $arg in
        --commit) COMMIT=true ;;
        --check) CHECK_ONLY=true ;;
        --assets-only) ASSETS_ONLY=true ;;
        --plugin-only) PLUGIN_ONLY=true ;;
        --tag=*) TAG_VERSION="${arg#*=}" ;;
        --zip=*) ZIP_FILE="${arg#*=}" ;;
        --user=*) SVN_USER="${arg#*=}" ;;
        --message=*) COMMIT_MSG="${arg#*=}" ;;
        --help)
            head -20 "$0" | tail -17
            exit 0
            ;;
        *) echo "Unknown option: $arg (use --help)"; exit 1 ;;
    esac
done

# SVN auth helper
svn_auth() {
    if [ -n "$SVN_USER" ] && [ -n "$SVN_PASS" ]; then
        echo "--username $SVN_USER --password $SVN_PASS --non-interactive --no-auth-cache"
    elif [ -n "$SVN_USER" ]; then
        echo "--username $SVN_USER"
    fi
}

# Entries that would leave a commit or a tag incomplete: unversioned (?),
# ignored (I), missing (!), obstructed (~) or conflicted (C). A file that is
# unversioned when trunk is copied to tags/X is missing from the release that
# WordPress.org serves.
svn_unclean_entries() {
    svn status --no-ignore "$SVN_DIR" | grep -E '^[?I!~C]' || true
}

check_working_copy() {
    if [ ! -d "$SVN_DIR/.svn" ]; then
        echo "  ERROR: no SVN working copy at $SVN_DIR"
        return 1
    fi
    local unclean
    unclean=$(svn_unclean_entries)
    if [ -n "$unclean" ]; then
        echo "  ERROR: entries that would not be committed as they are:"
        echo "$unclean" | sed 's/^/    /'
        return 1
    fi
    echo "  Working copy check passed: no unversioned, ignored, missing or conflicted entries."
    return 0
}

if [ "$CHECK_ONLY" = true ]; then
    echo "Checking SVN working copy: $SVN_DIR"
    if check_working_copy; then
        exit 0
    fi
    exit 1
fi

echo ""
echo "============================================================"
echo "  WordPress.org SVN Deploy"
echo "============================================================"
echo "  SVN URL:    $SVN_URL"
echo "  SVN local:  $SVN_DIR"
echo "  Plugin:     $PLUGIN_SLUG"
echo "  Commit:     $COMMIT"
[ -n "$ZIP_FILE" ] && echo "  Zip:        $ZIP_FILE"
[ -n "$TAG_VERSION" ] && echo "  Tag:        $TAG_VERSION"
echo "============================================================"
echo ""

# ============================================================
# Step 1: Checkout SVN (or update if already checked out)
# ============================================================
if [ -d "$SVN_DIR/.svn" ]; then
    echo "[1/6] Updating existing SVN checkout..."
    svn update "$SVN_DIR" $(svn_auth)
else
    echo "[1/6] Checking out SVN repository..."
    echo "  This may take a moment on first checkout..."
    svn checkout "$SVN_URL" "$SVN_DIR" $(svn_auth)
fi
echo ""

# ============================================================
# Step 2: Run build.py to create production zip
# ============================================================
if [ "$ASSETS_ONLY" = false ]; then
    if [ -z "$ZIP_FILE" ]; then
        echo "[2/6] Running build.py to create production zip..."
        python3 "$BUILD_SCRIPT"
        echo ""

        # Find the latest zip in build dir
        ZIP_FILE=$(ls -t "$SCRIPT_DIR"/${PLUGIN_SLUG}-*.zip 2>/dev/null | head -1)

        if [ -z "$ZIP_FILE" ] || [ ! -f "$ZIP_FILE" ]; then
            echo "  ERROR: No zip file found after build!"
            echo "  Expected: $SCRIPT_DIR/${PLUGIN_SLUG}-*.zip"
            exit 1
        fi
        echo "  Using build output: $(basename "$ZIP_FILE")"
    else
        echo "[2/6] Using provided zip: $ZIP_FILE"
        if [ ! -f "$ZIP_FILE" ]; then
            echo "  ERROR: Zip file not found: $ZIP_FILE"
            exit 1
        fi
    fi
    echo ""
else
    echo "[2/6] Skipping build (--assets-only)"
    echo ""
fi

# ============================================================
# Step 3: Update assets (banners, screenshots, icon)
# ============================================================
if [ "$PLUGIN_ONLY" = false ]; then
    echo "[3/6] Updating assets..."
    mkdir -p "$SVN_DIR/assets"

    # --- Banners ---
    echo "  Copying banners..."
    BANNER_COUNT=0
    for f in "$BANNER_DIR"/banner-*.png; do
        [ -f "$f" ] || continue
        cp "$f" "$SVN_DIR/assets/"
        BANNER_COUNT=$((BANNER_COUNT + 1))
    done
    echo "    $BANNER_COUNT banner files"

    # --- Screenshots ---
    echo "  Copying screenshots..."
    SCREENSHOT_COUNT=0
    for f in "$PLUGIN_DIR/assets"/screenshot-*.png; do
        [ -f "$f" ] || continue
        cp "$f" "$SVN_DIR/assets/"
        SCREENSHOT_COUNT=$((SCREENSHOT_COUNT + 1))
    done
    echo "    $SCREENSHOT_COUNT screenshot files"

    # --- Icon ---
    echo "  Copying icons..."
    ICON_COUNT=0
    if [ -f "$PLUGIN_DIR/assets/icon.svg" ]; then
        cp "$PLUGIN_DIR/assets/icon.svg" "$SVN_DIR/assets/"
        ICON_COUNT=$((ICON_COUNT + 1))
    fi
    for f in "$PLUGIN_DIR/assets"/icon-*.png; do
        [ -f "$f" ] || continue
        cp "$f" "$SVN_DIR/assets/"
        ICON_COUNT=$((ICON_COUNT + 1))
    done
    echo "    $ICON_COUNT icon files"
    echo ""
else
    echo "[3/6] Skipping assets (--plugin-only)"
    echo ""
fi

# ============================================================
# Step 4: Unzip build into trunk
# ============================================================
if [ "$ASSETS_ONLY" = false ]; then
    echo "[4/6] Extracting build zip into trunk..."

    # Clear trunk contents but preserve .svn metadata
    if [ -d "$SVN_DIR/trunk" ]; then
        # Remove all files/dirs in trunk except .svn
        find "$SVN_DIR/trunk" -mindepth 1 -maxdepth 1 ! -name '.svn' -exec rm -rf {} +
    else
        mkdir -p "$SVN_DIR/trunk"
    fi

    # Unzip — the zip contains plugin-slug/ as root folder, strip it into trunk/
    TEMP_UNZIP=$(mktemp -d)
    unzip -q "$ZIP_FILE" -d "$TEMP_UNZIP"

    # The zip has a root folder (plugin slug), move its contents to trunk
    ZIP_ROOT=$(ls "$TEMP_UNZIP")
    cp -a "$TEMP_UNZIP/$ZIP_ROOT/"* "$SVN_DIR/trunk/"
    rm -rf "$TEMP_UNZIP"

    FILE_COUNT=$(find "$SVN_DIR/trunk" -type f | wc -l)
    echo "    Extracted $FILE_COUNT files to trunk/"
    echo ""
else
    echo "[4/6] Skipping trunk (--assets-only)"
    echo ""
fi

# ============================================================
# Step 4b: Stage trunk add/remove BEFORE tagging
# ------------------------------------------------------------
# `svn cp trunk tags/X` only copies VERSIONED items. Brand-new
# files (e.g. a newly added class) are still unversioned right
# after the zip is extracted, so without this step they are
# missing from the tag — which is the package WordPress.org
# actually serves — causing a class-not-found fatal on update.
# Therefore: schedule adds/removes on trunk FIRST, then tag.
# ============================================================
if [ "$ASSETS_ONLY" = false ]; then
    echo "[4b/6] Staging trunk additions/removals before tag copy..."
    (cd "$SVN_DIR" && svn add --force trunk/ 2>/dev/null || true)
    (cd "$SVN_DIR" && svn status trunk/ | grep '^\!' | awk '{print $2}' | xargs -r svn delete --force 2>/dev/null || true)
    echo "    Trunk staged."
    echo ""
fi

# ============================================================
# Step 5: Create tag if requested
# ============================================================
if [ -n "$TAG_VERSION" ]; then
    echo "[5/6] Creating tag $TAG_VERSION (cheap copy from trunk)..."
    if [ -d "$SVN_DIR/tags/$TAG_VERSION" ]; then
        echo "    WARNING: Tag $TAG_VERSION already exists, removing..."
        # If SVN-tracked, use svn rm; otherwise fall back to rm -rf
        (cd "$SVN_DIR" && svn rm --force "tags/$TAG_VERSION" 2>/dev/null) || rm -rf "$SVN_DIR/tags/$TAG_VERSION"
    fi
    # svn cp creates a cheap copy (server-side pointer to trunk), not a file duplication
    (cd "$SVN_DIR" && svn cp trunk "tags/$TAG_VERSION")
    echo "    Created tags/$TAG_VERSION/ (copied from trunk)"
    echo ""
else
    echo "[5/6] No tag requested (use --tag=X.X.X to create one)"
    echo ""
fi

# ============================================================
# Step 6: SVN add/remove and status
# ============================================================
echo "[6/6] Updating SVN tracking..."

cd "$SVN_DIR"

# Add new files
svn add --force assets/ 2>/dev/null || true
if [ "$ASSETS_ONLY" = false ]; then
    svn add --force trunk/ 2>/dev/null || true
fi
# Note: tags/$TAG_VERSION/ is already scheduled as added-with-history by `svn cp` above,
# so no `svn add` is needed here.

# Remove deleted files from SVN tracking
svn status | grep '^\!' | awk '{print $2}' | xargs -r svn delete --force 2>/dev/null || true

# Set SVN mime type for binary files (prevents diff noise)
for ext in png jpg jpeg gif; do
    find assets/ -name "*.${ext}" -exec svn propset svn:mime-type "image/${ext}" {} \; 2>/dev/null || true
done
# SVG needs the full "image/svg+xml" type — browsers won't render as image/svg
find assets/ -name "*.svg" -exec svn propset svn:mime-type "image/svg+xml" {} \; 2>/dev/null || true

echo ""
echo "============================================================"
echo "  SVN Status Summary"
echo "============================================================"

ADDED=$(svn status | grep '^A' | wc -l)
MODIFIED=$(svn status | grep '^M' | wc -l)
DELETED=$(svn status | grep '^D' | wc -l)
TOTAL=$((ADDED + MODIFIED + DELETED))

echo "  Added:    $ADDED files"
echo "  Modified: $MODIFIED files"
echo "  Deleted:  $DELETED files"
echo "  Total:    $TOTAL changes"
echo ""

# Show breakdown by directory
echo "  By directory:"
echo "    assets/:  $(svn status assets/ 2>/dev/null | grep -c '^[AMD]') changes"
echo "    trunk/:   $(svn status trunk/ 2>/dev/null | grep -c '^[AMD]') changes"
[ -n "$TAG_VERSION" ] && echo "    tags/$TAG_VERSION/: $(svn status "tags/$TAG_VERSION/" 2>/dev/null | grep -c '^[AMD]') changes"
echo ""

# ============================================================
# Commit or show instructions
# ============================================================
if [ "$COMMIT" = true ]; then
    echo "  Checking the working copy before commit..."
    if ! check_working_copy; then
        echo "  Commit aborted. Fix the entries above, then re-run."
        exit 1
    fi
    echo ""
    echo "============================================================"
    echo "  Committing: $COMMIT_MSG"
    echo "============================================================"
    svn commit "$SVN_DIR" -m "$COMMIT_MSG" $(svn_auth)
    echo ""
    echo "  Done! Changes are live on WordPress.org."
else
    echo "============================================================"
    echo "  Ready to review (not committed yet)"
    echo "============================================================"
    echo ""
    check_working_copy || echo "  A commit would be refused until these entries are fixed."
    echo ""
    echo "  Review:"
    echo "    svn status $SVN_DIR/assets/       # Asset changes"
    echo "    svn status $SVN_DIR/trunk/        # Plugin changes"
    echo "    svn diff $SVN_DIR/trunk/readme.txt  # Check readme"
    echo ""
    echo "  Verify assets:"
    echo "    ls $SVN_DIR/assets/banner-*.png    # Banners ($BANNER_COUNT)"
    echo "    ls $SVN_DIR/assets/screenshot-*    # Screenshots ($SCREENSHOT_COUNT)"
    echo "    ls $SVN_DIR/assets/icon*            # Icons"
    echo ""
    echo "  Commit:"
    echo "    svn commit $SVN_DIR -m \"$COMMIT_MSG\""
    echo "    # or re-run: ./svn-deploy.sh --commit"
    echo ""
fi

echo "============================================================"
