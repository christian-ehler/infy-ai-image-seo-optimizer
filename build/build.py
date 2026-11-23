#!/usr/bin/env python3
"""
Infy - AI Image SEO Optimizer - Production Build Script

Creates a production-ready WordPress plugin zip file with:
- CSS concatenation and minification via npm
- JavaScript minification
- Excluded development files
- Proper versioning

Usage:
    python3 build.py              # Full production build (excludes WP.org assets)
    python3 build.py --submit     # Build for WordPress.org submission (includes banners/screenshots)
    python3 build.py --dev        # Development build (no minification)
    python3 build.py --css-only   # Only rebuild CSS
    python3 build.py --clean      # Clean build artifacts
    python3 build.py --check      # Check versions + plugin root allow-list (no build)
    python3 build.py --help       # Show help

Author: InfyPress
Version: 1.0.0
"""

import os
import sys
import shutil
import zipfile
import re
import subprocess
import tempfile
import argparse
import json
from pathlib import Path
from datetime import datetime
from typing import Optional, List, Tuple

# =============================================================================
# Configuration
# =============================================================================

PLUGIN_SLUG = 'infy-ai-image-seo-optimizer'
SCRIPT_DIR = Path(__file__).parent.resolve()  # build/ directory
SOURCE_DIR = SCRIPT_DIR.parent  # Plugin root directory (one level up)
BUILD_DIR = SCRIPT_DIR  # Output goes to build/ directory

# Files and directories to exclude from production build
# Uses .distignore patterns plus additional exclusions
EXCLUDE_PATTERNS = [
    # Development directories
    '.git',
    '.github',
    '.claude',
    '.vscode',
    '.idea',
    '.wordpress-org',
    'node_modules',
    'vendor/bin',
    'vendor/dealerdirect',
    'vendor/phpcompatibility',
    'vendor/phpcsstandards',
    'vendor/squizlabs',
    'vendor/wp-coding-standards',
    'tests',
    'build',
    'backups',
    'docs',
    'tools',
    'scripts',
    'changelog',

    # Development files
    '.gitignore',
    '.gitattributes',
    '.editorconfig',
    '.eslintrc*',
    '.prettierrc*',
    '.stylelintrc*',
    'phpcs.xml*',
    'phpstan.neon',
    # composer.json is kept for WordPress plugin requirements
    'composer.lock',
    'package.json',
    'package-lock.json',
    'tsconfig.json',
    'webpack.config.js',
    'postcss.config.js',

    # Build scripts
    'build.py',
    '*.sh',
    '*.bat',
    '*.ps1',

    # Test files
    'test-*.php',
    'phpunit.xml*',
    '.phpunit.result.cache',

    # Temporary/backup files
    '*.log',
    '*.tmp',
    '*.bak',
    '*.swp',
    '*~',
    '*.orig',
    '.DS_Store',
    'Thumbs.db',
    'desktop.ini',
    '.fuse_hidden*',

    # Source maps
    '*.map',

    # Database/config
    '*.sql',
    '.env*',
    'wp-config*.php',

    # Archives
    '*.zip',
    '*.tar*',

    # Documentation (dev)
    '*.md',

    # Design files
    '*.psd',
    '*.ai',
    '*.sketch',
    '*.fig',
    '*-mockup.html',

    # License test
    'test-license-system.php',
]

# Root of the plugin: default-deny. Only these entries are copied into the zip
# (the root of the 1.0.8 release). Everything else at the root must be listed in
# DEV_ROOT_ENTRIES, or the build stops, so a stray file (screenshot, scratch
# script, export) can never ship by accident.
SHIP_ROOT_ENTRIES = {
    f'{PLUGIN_SLUG}.php',
    'LICENSE.txt',
    'readme.txt',
    'uninstall.php',
    'composer.json',
    'assets',
    'includes',
    'languages',
    'vendor',
}

# Root entries that belong to the repository or the development machine and
# never ship. Names starting with "." are always treated as development files.
DEV_ROOT_ENTRIES = {
    'build',
    'changelog',
    'docs',
    'node_modules',
    'scripts',
    'tests',
    'CHANGELOG.md',
    'CLAUDE.md',
    'CONTRIBUTING.md',
    'README.md',
    'SECURITY.md',
    'composer.lock',
    'package.json',
    'package-lock.json',
    'phpcs.xml',
    'phpcs.xml.dist',
    'phpstan.neon',
    'phpstan.neon.dist',
    'phpstan-baseline.neon',
}

# WordPress.org directory assets (banners, screenshots, icons)
# These are only needed for WordPress.org SVN submission, not regular builds
# Use --submit flag to include these in the build
WPORG_ASSETS_PATTERNS = [
    'banner-',      # banner-772x250.png, banner-1544x500.png, etc.
    'screenshot-',  # screenshot-1.png, screenshot-2.png, etc.
    'icon-',        # icon-128x128.png, icon-256x256.png, etc.
]

# CSS files to exclude (we use dist/ bundles instead)
# Excludes all source CSS - the dist files are handled separately
CSS_SOURCE_EXCLUDES = [
    'assets/css/framework/',
    'assets/css/plugin/',
    'assets/css/admin-style.css',
    'assets/css/modals.css',
    'assets/css/optimize-icon.css',
    'assets/css/main.css',
    'assets/css/dist/',  # Exclude all dist by default, we'll selectively include
]

# CSS dist files to include (production = minified only, dev = all)
CSS_DIST_INCLUDES_PROD = [
    'assets/css/dist/infyp.css',
    'assets/css/dist/framework.css',
    'assets/css/dist/plugin.css',
    'assets/css/dist/infyp.min.css',
    'assets/css/dist/framework.min.css',
    'assets/css/dist/plugin.min.css',
]

CSS_DIST_INCLUDES_DEV = [
    'assets/css/dist/infyp.css',
    'assets/css/dist/infyp.min.css',
    'assets/css/dist/framework.css',
    'assets/css/dist/framework.min.css',
    'assets/css/dist/plugin.css',
    'assets/css/dist/plugin.min.css',
]

# JS files to exclude (we use dist/ bundles instead)
# Excludes all source JS modules - the dist files are handled separately
JS_SOURCE_EXCLUDES = [
    'assets/js/modules/',
    'assets/js/bundles/',
    'assets/js/admin-script.js',  # Replaced by infyp-admin.min.js bundle
    'assets/js/setup-wizard.js',  # Replaced by setup-wizard.min.js
    'assets/js/attachment-page.js',  # Replaced by attachment-page.min.js
    'assets/js/media-library.js',  # Replaced by media-library.min.js
    'assets/js/account-settings.js',  # Replaced by account-settings.min.js
    'assets/js/seo-score-page.js',  # Replaced by seo-score-page.min.js
    'assets/js/debug-page.js',  # Replaced by debug-page.min.js
    'assets/js/search-console.js',  # Replaced by search-console.min.js
    'assets/js/api-usage-chart.js',  # Replaced by api-usage-chart.min.js
    'assets/js/rest-api-chart.js',  # Replaced by rest-api-chart.min.js
    'assets/js/redirect-settings.js',  # Replaced by redirect-settings.min.js
    'assets/js/dist/',  # Exclude all dist by default, we'll selectively include
]

# JS dist files to include (production = both minified AND unminified for WordPress.org compliance)
JS_DIST_INCLUDES_PROD = [
    # Main admin bundle
    'assets/js/dist/infyp-admin.js',
    'assets/js/dist/infyp-admin.min.js',
    # Standalone scripts - unminified (WordPress.org requires human-readable code)
    'assets/js/dist/setup-wizard.js',
    'assets/js/dist/attachment-page.js',
    'assets/js/dist/media-library.js',
    'assets/js/dist/account-settings.js',
    'assets/js/dist/seo-score-page.js',
    'assets/js/dist/debug-page.js',
    'assets/js/dist/dashboard.js',
    'assets/js/dist/api-usage-chart.js',
    'assets/js/dist/rest-api-chart.js',
    # Standalone scripts - minified (for production use)
    'assets/js/dist/setup-wizard.min.js',
    'assets/js/dist/attachment-page.min.js',
    'assets/js/dist/media-library.min.js',
    'assets/js/dist/account-settings.min.js',
    'assets/js/dist/seo-score-page.min.js',
    'assets/js/dist/debug-page.min.js',
    'assets/js/dist/dashboard.min.js',
    'assets/js/dist/api-usage-chart.min.js',
    'assets/js/dist/rest-api-chart.min.js',
    'assets/js/dist/search-console.js',
    'assets/js/dist/search-console.min.js',
    'assets/js/dist/redirect-settings.js',
    'assets/js/dist/redirect-settings.min.js',
]

JS_DIST_INCLUDES_DEV = [
    # Main admin bundle
    'assets/js/dist/infyp-admin.js',
    'assets/js/dist/infyp-admin.min.js',
    # Standalone scripts - unminified
    'assets/js/dist/setup-wizard.js',
    'assets/js/dist/attachment-page.js',
    'assets/js/dist/media-library.js',
    'assets/js/dist/account-settings.js',
    'assets/js/dist/seo-score-page.js',
    'assets/js/dist/debug-page.js',
    'assets/js/dist/dashboard.js',
    'assets/js/dist/api-usage-chart.js',
    'assets/js/dist/rest-api-chart.js',
    'assets/js/dist/search-console.js',
    # Standalone scripts - minified
    'assets/js/dist/setup-wizard.min.js',
    'assets/js/dist/attachment-page.min.js',
    'assets/js/dist/media-library.min.js',
    'assets/js/dist/account-settings.min.js',
    'assets/js/dist/seo-score-page.min.js',
    'assets/js/dist/debug-page.min.js',
    'assets/js/dist/dashboard.min.js',
    'assets/js/dist/api-usage-chart.min.js',
    'assets/js/dist/rest-api-chart.min.js',
    'assets/js/dist/search-console.min.js',
    'assets/js/dist/redirect-settings.js',
    'assets/js/dist/redirect-settings.min.js',
]

# Terminal colors
class Colors:
    RED = '\033[0;31m'
    GREEN = '\033[0;32m'
    YELLOW = '\033[1;33m'
    BLUE = '\033[0;34m'
    CYAN = '\033[0;36m'
    MAGENTA = '\033[0;35m'
    NC = '\033[0m'  # No Color
    BOLD = '\033[1m'


def print_color(color: str, message: str) -> None:
    """Print colored output to terminal."""
    print(f"{color}{message}{Colors.NC}")


def print_header(title: str) -> None:
    """Print a section header."""
    print()
    print_color(Colors.CYAN, "╔" + "═" * 60 + "╗")
    print_color(Colors.CYAN, f"║  {title:<57} ║")
    print_color(Colors.CYAN, "╚" + "═" * 60 + "╝")


def print_success(message: str) -> None:
    """Print success message."""
    print_color(Colors.GREEN, f"  ✅ {message}")


def print_warning(message: str) -> None:
    """Print warning message."""
    print_color(Colors.YELLOW, f"  ⚠️  {message}")


def print_error(message: str) -> None:
    """Print error message."""
    print_color(Colors.RED, f"  ❌ {message}")


def print_info(message: str) -> None:
    """Print info message."""
    print_color(Colors.BLUE, f"  ℹ️  {message}")


# =============================================================================
# Version Management
# =============================================================================

def get_plugin_version() -> str:
    """Extract version from the main plugin file."""
    plugin_file = SOURCE_DIR / f'{PLUGIN_SLUG}.php'

    if not plugin_file.exists():
        print_error(f"Plugin file {plugin_file} not found!")
        return '1.0.0'

    content = plugin_file.read_text(encoding='utf-8')

    # Look for Version: X.X.X in plugin header
    version_match = re.search(r'^\s*\*\s*Version:\s*(.+)$', content, re.MULTILINE)
    if version_match:
        return version_match.group(1).strip()

    # Fallback: look for define('VERSION', 'X.X.X')
    version_match = re.search(r"define\s*\(\s*['\"].*VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)", content)
    if version_match:
        return version_match.group(1).strip()

    return '1.0.0'


CHANGELOG_RELEASE_HEADING = re.compile(r'^## \[(\d+\.\d+\.\d+[^\]]*)\]', re.MULTILINE)


def _read_json(path: Path) -> Optional[dict]:
    """Parse a JSON file; None if it is missing or invalid."""
    try:
        return json.loads(path.read_text(encoding='utf-8'))
    except (OSError, ValueError):
        return None


def _read_version_sources() -> dict:
    """Read the plugin version from every authoritative location.

    Returns a dict keyed by human-readable source name. Values are the
    version string found, or None if the pattern did not match. Optional
    sources (package-lock.json, CHANGELOG.md) are left out when the file
    does not exist.
    """
    plugin_file = SOURCE_DIR / f'{PLUGIN_SLUG}.php'
    readme_file = SOURCE_DIR / 'readme.txt'
    package_file = SOURCE_DIR / 'package.json'
    lock_file = SOURCE_DIR / 'package-lock.json'
    changelog_file = SOURCE_DIR / 'CHANGELOG.md'

    plugin_content = plugin_file.read_text(encoding='utf-8') if plugin_file.exists() else ''
    readme_content = readme_file.read_text(encoding='utf-8') if readme_file.exists() else ''

    header = re.search(r'^\s*\*\s*Version:\s*(.+)$', plugin_content, re.MULTILINE)
    const = re.search(
        r"define\s*\(\s*['\"]INFYP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)",
        plugin_content,
    )
    stable = re.search(r'^Stable tag:\s*(.+)$', readme_content, re.MULTILINE)
    package = _read_json(package_file) or {}

    sources = {
        'Plugin header (Version:)': header.group(1).strip() if header else None,
        'INFYP_VERSION constant':   const.group(1).strip() if const else None,
        'readme.txt (Stable tag:)': stable.group(1).strip() if stable else None,
        'package.json (version)':   package.get('version'),
    }

    if lock_file.exists():
        lock = _read_json(lock_file) or {}
        sources['package-lock.json (version)'] = lock.get('version')
        sources['package-lock.json (packages."".version)'] = (
            lock.get('packages', {}).get('', {}).get('version')
        )

    if changelog_file.exists():
        latest = CHANGELOG_RELEASE_HEADING.search(changelog_file.read_text(encoding='utf-8'))
        sources['CHANGELOG.md (latest release heading)'] = latest.group(1) if latest else None

    return sources


def check_version_consistency() -> bool:
    """Verify every authoritative version reference is in sync.

    Prints a detailed error listing mismatches. Returns True on sync.
    """
    versions = _read_version_sources()

    missing = [name for name, value in versions.items() if not value]
    if missing:
        print_error("Missing version strings:")
        for name in missing:
            print_error(f"  {name}")
        return False

    unique = set(versions.values())
    if len(unique) != 1:
        print_error("Version mismatch detected — these must all match:")
        for name, value in versions.items():
            print_error(f"  {name}: {value}")
        print_error("Fix the stale entries by hand, or set a new version with: python3 build/build.py --bump=X.Y.Z")
        return False

    return True


def _bump_json_version(path: Path, new_version: str, keys: List[Tuple[str, ...]]) -> Optional[str]:
    """Return the file content with the version under each key path replaced.

    Rewrites the first len(keys) '"version": "..."' entries in place so the
    file's formatting is kept, then parses both versions and verifies that
    exactly those keys changed. Returns None (and prints why) on any mismatch.
    """
    content = path.read_text(encoding='utf-8')
    updated, count = re.subn(
        r'("version"\s*:\s*")[^"]*(")',
        rf'\g<1>{new_version}\g<2>',
        content,
        count=len(keys),
    )
    if count != len(keys):
        print_error(f"Could not find {len(keys)} version field(s) in {path.name}")
        return None

    expected = json.loads(content)
    for key_path in keys:
        node = expected
        for key in key_path[:-1]:
            node = node.get(key, {})
        if key_path[-1] not in node:
            print_error(f"{path.name}: missing {'.'.join(repr(k) for k in key_path)}")
            return None
        node[key_path[-1]] = new_version

    if json.loads(updated) != expected:
        print_error(f"{path.name}: version fields are not where expected; edit it by hand")
        return None
    return updated


def bump_version(new_version: str) -> int:
    """Bump the plugin version across every authoritative location.

    Rewrites:
      - Plugin header Version: and the INFYP_VERSION PHP constant
      - readme.txt Stable tag:
      - package.json version (and package-lock.json, if present)
    Prepends stubs (user fills in before deploy):
      - readme.txt == Changelog == and == Upgrade Notice == entries
      - CHANGELOG.md: a "## [X.Y.Z] - date" heading right below
        "## [Unreleased]", so the unreleased entries become the release entries
    Every file is computed first; nothing is written unless all succeed.
    """
    if not re.match(r'^\d+\.\d+\.\d+$', new_version):
        print_error(f"Invalid version format: '{new_version}' — expected X.Y.Z (e.g. 1.0.1)")
        return 1

    current = get_plugin_version()
    if current == new_version:
        print_warning(f"Version is already {new_version} — nothing to do")
        return 0

    print_header(f"Bumping version: {current} → {new_version}")

    plugin_file = SOURCE_DIR / f'{PLUGIN_SLUG}.php'
    readme_file = SOURCE_DIR / 'readme.txt'
    package_file = SOURCE_DIR / 'package.json'
    lock_file = SOURCE_DIR / 'package-lock.json'
    changelog_file = SOURCE_DIR / 'CHANGELOG.md'
    today = datetime.now().strftime('%Y-%m-%d')
    writes = []  # (path, new content, success message)

    # ---- Plugin PHP file: Version: header + INFYP_VERSION constant ----
    plugin_content = plugin_file.read_text(encoding='utf-8')

    plugin_content, n_header = re.subn(
        r'(^\s*\*\s*Version:\s*).+$',
        rf'\g<1>{new_version}',
        plugin_content,
        count=1,
        flags=re.MULTILINE,
    )
    if n_header != 1:
        print_error("Could not find 'Version:' header in plugin file")
        return 1

    plugin_content, n_const = re.subn(
        r"(define\s*\(\s*['\"]INFYP_VERSION['\"]\s*,\s*['\"])[^'\"]+(['\"]\s*\))",
        rf"\g<1>{new_version}\g<2>",
        plugin_content,
        count=1,
    )
    if n_const != 1:
        print_error("Could not find INFYP_VERSION constant definition")
        return 1

    writes.append((plugin_file, plugin_content, "Updated plugin header Version: and INFYP_VERSION constant"))

    # ---- readme.txt: Stable tag + changelog stub + upgrade notice stub ----
    readme_content = readme_file.read_text(encoding='utf-8')

    readme_content, n_stable = re.subn(
        r'(^Stable tag:\s*).+$',
        rf'\g<1>{new_version}',
        readme_content,
        count=1,
        flags=re.MULTILINE,
    )
    if n_stable != 1:
        print_error("Could not find 'Stable tag:' line in readme.txt")
        return 1

    changelog_stub = f"= {new_version} - {today} =\n* TODO: describe changes\n\n"
    readme_content, n_changelog = re.subn(
        r'(== Changelog ==\s*\n\s*\n)(= \d+\.\d+\.\d+)',
        rf'\g<1>{changelog_stub}\g<2>',
        readme_content,
        count=1,
    )
    if n_changelog != 1:
        print_error("Could not locate '== Changelog ==' section to prepend to")
        return 1

    notice_stub = f"= {new_version} =\nTODO: short upgrade notice.\n\n"
    readme_content, n_notice = re.subn(
        r'(== Upgrade Notice ==\s*\n\s*\n)(= \d+\.\d+\.\d+)',
        rf'\g<1>{notice_stub}\g<2>',
        readme_content,
        count=1,
    )
    if n_notice != 1:
        print_error("Could not locate '== Upgrade Notice ==' section to prepend to")
        return 1

    writes.append((readme_file, readme_content, "Updated readme.txt Stable tag: and prepended changelog + upgrade notice stubs"))

    # ---- package.json / package-lock.json ----
    package_content = _bump_json_version(package_file, new_version, [('version',)])
    if package_content is None:
        return 1
    writes.append((package_file, package_content, "Updated package.json version"))

    if lock_file.exists():
        lock_content = _bump_json_version(
            lock_file, new_version, [('version',), ('packages', '', 'version')]
        )
        if lock_content is None:
            return 1
        writes.append((lock_file, lock_content, "Updated package-lock.json version"))

    # ---- CHANGELOG.md: release heading below [Unreleased] ----
    if changelog_file.exists():
        changelog_content = changelog_file.read_text(encoding='utf-8')
        changelog_content, n_release = re.subn(
            r'^## \[Unreleased\][ \t]*\n',
            f'## [Unreleased]\n\n## [{new_version}] - {today}\n',
            changelog_content,
            count=1,
            flags=re.MULTILINE,
        )
        if n_release != 1:
            print_error("Could not find '## [Unreleased]' heading in CHANGELOG.md")
            return 1
        writes.append((changelog_file, changelog_content, f"Added '## [{new_version}] - {today}' to CHANGELOG.md"))

    for path, content, message in writes:
        path.write_text(content, encoding='utf-8')
        print_success(message)

    print()
    print_color(Colors.BOLD + Colors.GREEN, f"  ✅ Version bumped to {new_version}")
    print()
    print_info("Next steps:")
    print_info("  1. Edit readme.txt — replace TODO lines in changelog + upgrade notice")
    print_info("  2. Check CHANGELOG.md — the former [Unreleased] entries now sit under the new heading")
    print_info("  3. Run: python3 build/build.py")
    print_info(f"  4. Deploy: cd build && ./svn-deploy.sh --commit --tag={new_version} --message=\"Release {new_version}\"")

    return 0


# =============================================================================
# File Exclusion Logic
# =============================================================================

def matches_pattern(path_str: str, pattern: str) -> bool:
    """Check if a path matches an exclusion pattern."""
    import fnmatch

    # Normalize path separators
    path_str = path_str.replace('\\', '/')
    pattern = pattern.replace('\\', '/')

    # Check various matching strategies
    path_parts = path_str.split('/')

    # Direct pattern match
    if fnmatch.fnmatch(path_str, pattern):
        return True

    # Match against filename only
    filename = path_parts[-1] if path_parts else ''
    if fnmatch.fnmatch(filename, pattern):
        return True

    # Match against any path component
    for part in path_parts:
        if fnmatch.fnmatch(part, pattern):
            return True
        if part == pattern:
            return True

    # Multi-segment directory prefixes (e.g. "vendor/bin") are matched as
    # anchored path prefixes. Single-segment names are already handled by the
    # path-component check above; a bare substring test here wrongly excludes
    # unrelated files (e.g. pattern "changelog" matching the required view
    # "includes/views/changelog-page.php"), which causes fatal errors at runtime.
    if '/' in pattern and (path_str == pattern or path_str.startswith(pattern + '/')):
        return True

    return False


def find_unlisted_root_entries(base_path: Path = SOURCE_DIR) -> List[str]:
    """Return root entries that are on neither root allow-list (dot-names excepted)."""
    return sorted(
        entry.name for entry in base_path.iterdir()
        if not entry.name.startswith('.')
        and entry.name not in SHIP_ROOT_ENTRIES
        and entry.name not in DEV_ROOT_ENTRIES
    )


def check_root_entries() -> bool:
    """Stop the build when the plugin root holds a file nobody listed.

    The zip only takes root entries from SHIP_ROOT_ENTRIES. Anything else must
    be a known development entry (DEV_ROOT_ENTRIES); an unknown one is most
    likely a stray file (screenshot, export, scratch script) left behind.
    """
    unlisted = find_unlisted_root_entries()
    if not unlisted:
        return True

    print_error("Unexpected files in the plugin root:")
    for name in unlisted:
        print_error(f"  {name}")
    print_error("Remove them, or list them in build/build.py:")
    print_error("  SHIP_ROOT_ENTRIES if they belong in the zip, DEV_ROOT_ENTRIES if they never ship.")
    return False


def should_exclude(file_path: Path, base_path: Path, use_dist_assets: bool = True, submit_mode: bool = False) -> bool:
    """Check if a file should be excluded from the build.

    Args:
        file_path: Path to the file being checked
        base_path: Base path of the plugin
        use_dist_assets: Whether to use dist assets instead of source
        submit_mode: If True, include WordPress.org assets (banners, screenshots, icons)
    """
    try:
        relative_path = file_path.relative_to(base_path)
    except ValueError:
        return True

    path_str = str(relative_path).replace('\\', '/')
    filename = file_path.name

    # Root is default-deny: only allow-listed entries ship.
    if len(relative_path.parts) == 1 and filename not in SHIP_ROOT_ENTRIES:
        return True

    # Always exclude hidden files (starting with .)
    if filename.startswith('.'):
        return True

    # Exclude FUSE hidden files
    if '.fuse_hidden' in filename:
        return True

    # Check standard exclusion patterns
    for pattern in EXCLUDE_PATTERNS:
        if matches_pattern(path_str, pattern):
            return True

    # WordPress.org assets handling (banners, screenshots, icons)
    # Only include these when submit_mode is True
    if not submit_mode:
        for wporg_pattern in WPORG_ASSETS_PATTERNS:
            if filename.startswith(wporg_pattern) and filename.endswith('.png'):
                return True

    # CSS handling: exclude source files, keep only dist
    if use_dist_assets:
        for css_exclude in CSS_SOURCE_EXCLUDES:
            if path_str.startswith(css_exclude.replace('\\', '/')):
                return True

        # JS handling: exclude source modules, keep only dist
        for js_exclude in JS_SOURCE_EXCLUDES:
            if path_str.startswith(js_exclude.replace('\\', '/')):
                return True

    return False


def should_include_css_dist(file_path: Path, base_path: Path, dev_mode: bool = False) -> bool:
    """Check if this is a CSS dist file that should be included."""
    try:
        relative_path = file_path.relative_to(base_path)
    except ValueError:
        return False

    path_str = str(relative_path).replace('\\', '/')

    # Select appropriate include list based on build mode
    include_list = CSS_DIST_INCLUDES_DEV if dev_mode else CSS_DIST_INCLUDES_PROD

    for include_path in include_list:
        if path_str == include_path.replace('\\', '/'):
            return True

    return False


def should_include_js_dist(file_path: Path, base_path: Path, dev_mode: bool = False) -> bool:
    """Check if this is a JS dist file that should be included."""
    try:
        relative_path = file_path.relative_to(base_path)
    except ValueError:
        return False

    path_str = str(relative_path).replace('\\', '/')

    # Select appropriate include list based on build mode
    include_list = JS_DIST_INCLUDES_DEV if dev_mode else JS_DIST_INCLUDES_PROD

    for include_path in include_list:
        if path_str == include_path.replace('\\', '/'):
            return True

    return False


# =============================================================================
# NPM/CSS Build
# =============================================================================

def check_node_installed() -> bool:
    """Check if Node.js is installed."""
    try:
        result = subprocess.run(
            ['node', '--version'],
            capture_output=True,
            text=True
        )
        return result.returncode == 0
    except FileNotFoundError:
        return False


def check_npm_dependencies() -> bool:
    """Check if npm dependencies are installed."""
    node_modules = SOURCE_DIR / 'node_modules'
    return node_modules.exists() and any(node_modules.iterdir())


def install_npm_dependencies() -> bool:
    """Install npm dependencies."""
    print_info("Installing npm dependencies...")

    try:
        result = subprocess.run(
            ['npm', 'install'],
            cwd=SOURCE_DIR,
            capture_output=True,
            text=True
        )

        if result.returncode != 0:
            print_error(f"npm install failed: {result.stderr}")
            return False

        print_success("npm dependencies installed")
        return True

    except FileNotFoundError:
        print_error("npm not found. Please install Node.js")
        return False


def regenerate_autoloader() -> bool:
    """Regenerate the Composer optimized autoloader against the source tree.

    The plugin ships an optimized classmap (vendor/composer/autoload_classmap.php
    + autoload_static.php). If a new class file is added but the classmap is not
    regenerated, the shipped autoloader cannot find the class and the plugin
    fatals on load. This step makes the build self-correcting: every production
    build refreshes the classmap so newly added classes are always included.
    """
    print_header("Regenerating Composer Autoloader")

    composer_json = SOURCE_DIR / 'composer.json'
    if not composer_json.exists():
        print_warning("No composer.json found — skipping autoloader regeneration")
        return True

    try:
        result = subprocess.run(
            ['composer', 'dump-autoload', '-o', '--no-dev', '--no-interaction'],
            cwd=str(SOURCE_DIR),
            capture_output=True,
            text=True,
            timeout=180,
        )
    except FileNotFoundError:
        print_error("composer not found on PATH — cannot regenerate the autoloader.")
        print_error("The shipped classmap may be stale; newly added classes would")
        print_error("fatal on load. Install composer or run 'composer dump-autoload -o'")
        print_error("manually before releasing. Aborting build to prevent a broken release.")
        return False
    except subprocess.TimeoutExpired:
        print_error("composer dump-autoload timed out — aborting build.")
        return False

    if result.returncode != 0:
        print_error("composer dump-autoload failed — aborting build:")
        print_error((result.stderr or result.stdout or '').strip())
        return False

    # Sanity check: the classmap must cover the plugin's own classes.
    classmap = SOURCE_DIR / 'vendor' / 'composer' / 'autoload_classmap.php'
    if classmap.exists():
        mapped = classmap.read_text(encoding='utf-8', errors='ignore').count("'IPAIS\\\\")
        print_success(f"Autoloader regenerated ({mapped} plugin classes mapped)")
    else:
        print_warning("Autoloader regenerated but classmap file not found for verification")
    return True


def build_css(minify: bool = True) -> bool:
    """Build CSS using npm script."""
    print_header("Building CSS Assets")

    # Check Node.js
    if not check_node_installed():
        print_error("Node.js not found. Please install Node.js to build CSS.")
        return False

    # Check/install dependencies
    if not check_npm_dependencies():
        if not install_npm_dependencies():
            return False

    # Run CSS build
    script = 'build:css' if minify else 'build:css:dev'
    print_info(f"Running npm run {script}...")

    try:
        result = subprocess.run(
            ['npm', 'run', script],
            cwd=SOURCE_DIR,
            capture_output=True,
            text=True
        )

        if result.returncode != 0:
            print_error(f"CSS build failed: {result.stderr}")
            print(result.stdout)
            return False

        # Print output
        for line in result.stdout.strip().split('\n'):
            if line.strip():
                print(f"    {line}")

        print_success("CSS build complete")
        return True

    except FileNotFoundError:
        print_error("npm not found")
        return False


# =============================================================================
# JavaScript Build
# =============================================================================

def build_javascript(minify: bool = True) -> bool:
    """Build JavaScript using npm script (Rollup bundling)."""
    print_header("Building JavaScript Assets")

    # Check Node.js
    if not check_node_installed():
        print_error("Node.js not found. Please install Node.js to build JS.")
        return False

    # Check/install dependencies
    if not check_npm_dependencies():
        if not install_npm_dependencies():
            return False

    # Run JS build
    script = 'build:js' if minify else 'build:js:dev'
    print_info(f"Running npm run {script}...")

    try:
        result = subprocess.run(
            ['npm', 'run', script],
            cwd=SOURCE_DIR,
            capture_output=True,
            text=True
        )

        if result.returncode != 0:
            print_error(f"JS build failed: {result.stderr}")
            print(result.stdout)
            return False

        # Print output
        for line in result.stdout.strip().split('\n'):
            if line.strip():
                print(f"    {line}")

        print_success("JavaScript build complete")
        return True

    except FileNotFoundError:
        print_error("npm not found")
        return False


def minify_js_file(js_path: Path, output_path: Path) -> bool:
    """Minify a single JavaScript file using uglifyjs (fallback)."""
    try:
        result = subprocess.run([
            'npx', 'uglifyjs', str(js_path),
            '--compress', 'drop_console=false,keep_fargs=true',
            '--mangle', 'reserved=["infyp_vars","wp","jQuery","$"]',
            '--keep-fnames',
            '--output', str(output_path)
        ], capture_output=True, text=True, cwd=SOURCE_DIR)

        return result.returncode == 0

    except Exception:
        return False


def minify_javascript(build_path: Path) -> None:
    """Minify JavaScript files in the build directory (legacy fallback)."""
    print_header("Minifying JavaScript")

    js_files = list(build_path.rglob('*.js'))
    js_to_minify = [f for f in js_files if not f.name.endswith('.min.js')]

    if not js_to_minify:
        print_info("No JavaScript files to minify")
        return

    print_info(f"Found {len(js_to_minify)} JavaScript files to minify")

    success_count = 0
    for js_file in js_to_minify:
        temp_file = js_file.parent / f"{js_file.stem}.tmp.js"

        if minify_js_file(js_file, temp_file):
            shutil.move(str(temp_file), str(js_file))
            success_count += 1
            print(f"    ✓ {js_file.name}")
        else:
            print_warning(f"Could not minify {js_file.name} - keeping original")
            if temp_file.exists():
                temp_file.unlink()

    print_success(f"Minified {success_count}/{len(js_to_minify)} JavaScript files")


# =============================================================================
# PHP CSS Enqueue Transformation for Production
# =============================================================================

def transform_css_enqueues_for_production(build_path: Path) -> None:
    """
    Transform PHP files to use bundled CSS instead of individual files.

    In development, we load individual CSS files for easier debugging.
    In production, we load a single bundled infyp.min.css for performance.
    """
    print_header("Transforming CSS Enqueues for Production")

    script_manager_path = build_path / 'includes' / 'Core' / 'INFYP_Script_Manager.php'

    if not script_manager_path.exists():
        print_warning("INFYP_Script_Manager.php not found - skipping CSS transform")
        return

    content = script_manager_path.read_text(encoding='utf-8')
    original_content = content

    # The new production CSS enqueue code - single bundled file
    production_css_code = '''            // Production: Load bundled CSS
            wp_enqueue_style('infyp-admin', INFYP_PLUGIN_URL . 'assets/css/dist/infyp.min.css', ['common', 'forms'], INFYP_VERSION);'''

    # Find and replace the CSS enqueue block in enqueue_admin_scripts
    # Match from the first framework CSS comment to the optimize-icon enqueue
    import re

    # Pattern to match all the individual CSS enqueue statements
    css_block_pattern = r'''(// Framework: Variables \(must be first\)
            wp_enqueue_style\('infyp-variables'.*?
            // Modal content styles
            wp_enqueue_style\('infyp-modals'.*?
            wp_enqueue_style\('infyp-optimize-icon'.*?INFYP_VERSION\);)'''

    # Use a simpler approach - find the section markers
    start_marker = "// Framework: Variables (must be first)"
    end_marker = "wp_enqueue_style('infyp-optimize-icon'"

    start_idx = content.find(start_marker)
    if start_idx == -1:
        print_warning("Could not find CSS enqueue start marker")
        return

    # Find the end of the optimize-icon line
    end_idx = content.find(end_marker, start_idx)
    if end_idx == -1:
        print_warning("Could not find CSS enqueue end marker")
        return

    # Find the end of that line (the semicolon and closing paren)
    line_end = content.find(");", end_idx)
    if line_end == -1:
        print_warning("Could not find line end")
        return

    line_end += 2  # Include ");"

    # Replace the block
    content = content[:start_idx] + production_css_code + content[line_end:]

    # Also need to handle the API keys page-specific style
    # Change it to depend on infyp-admin instead of infyp-variables
    content = content.replace(
        "wp_enqueue_style('infyp-api-keys', INFYP_PLUGIN_URL . 'assets/css/plugin/api-keys.css', ['infyp-variables'], INFYP_VERSION);",
        "// API keys styles are included in infyp.min.css bundle"
    )

    # Write back
    if content != original_content:
        script_manager_path.write_text(content, encoding='utf-8')
        print_success("Transformed INFYP_Script_Manager.php for production CSS")
    else:
        print_info("No CSS enqueue changes needed")

    # Also transform the Setup Wizard if it exists
    setup_wizard_path = build_path / 'includes' / 'Admin' / 'INFYP_Setup_Wizard.php'
    if setup_wizard_path.exists():
        wizard_content = setup_wizard_path.read_text(encoding='utf-8')
        original_wizard = wizard_content

        # The Setup Wizard has register + enqueue pairs that need to be replaced entirely
        # Find and replace the CSS registration/enqueue block

        # Old block pattern (registers variables, setup-wizard and enqueues each;
        # the page background lives in setup-wizard.css since the Phase-1 wizard
        # groundwork — no inline styles)
        old_css_block = '''        // Register and enqueue CSS variables
        wp_register_style(
            'infyp-variables',
            INFYP_PLUGIN_URL . 'assets/css/framework/variables.css',
            [],
            INFYP_VERSION
        );
        wp_enqueue_style( 'infyp-variables' );

        // Register and enqueue wizard styles
        wp_register_style(
            'infyp-setup-wizard',
            INFYP_PLUGIN_URL . 'assets/css/plugin/setup-wizard.css',
            [ 'infyp-variables' ],
            INFYP_VERSION
        );
        wp_enqueue_style( 'infyp-setup-wizard' );'''

        # New block - single bundled CSS file (variables + wizard styles included)
        new_css_block = '''        // Production: Load bundled CSS (includes variables, wizard styles)
        wp_enqueue_style(
            'infyp-admin',
            INFYP_PLUGIN_URL . 'assets/css/dist/infyp.min.css',
            [],
            INFYP_VERSION
        );'''

        wizard_content = wizard_content.replace(old_css_block, new_css_block)

        if wizard_content != original_wizard:
            setup_wizard_path.write_text(wizard_content, encoding='utf-8')
            print_success("Transformed INFYP_Setup_Wizard.php for production CSS")
        else:
            print_warning("Setup Wizard CSS block not found or already transformed")

    # Transform INFYP_Admin_Pages.php - page-specific CSS enqueues
    admin_pages_path = build_path / 'includes' / 'Admin' / 'INFYP_Admin_Pages.php'
    if admin_pages_path.exists():
        admin_content = admin_pages_path.read_text(encoding='utf-8')
        original_admin = admin_content

        # These page-specific styles are now included in infyp.min.css bundle
        # Comment them out since they're loaded by the main bundle
        css_replacements = [
            # dashboard.css + dashboard.js (bundled into infyp.min.css and infyp-admin.min.js)
            (
                """wp_enqueue_style(
				'infyp-dashboard',
				INFYP_PLUGIN_URL . 'assets/css/plugin/dashboard.css',
				array(),
				INFYP_VERSION
			);""",
                "// Production: dashboard styles included in infyp.min.css bundle"
            ),
            # settings.css
            (
                """wp_enqueue_style(
				'infyp-settings',
				INFYP_PLUGIN_URL . 'assets/css/plugin/settings.css',
				array(),
				INFYP_VERSION
			);""",
                "// Production: settings styles included in infyp.min.css bundle"
            ),
            # account-settings.css
            (
                """wp_enqueue_style(
				'infyp-account-settings',
				INFYP_PLUGIN_URL . 'assets/css/plugin/account-settings.css',
				array(),
				INFYP_VERSION
			);""",
                "// Production: account-settings styles included in infyp.min.css bundle"
            ),
            # seo-score-page.css
            (
                """wp_enqueue_style(
            'infyp-seo-score-page',
            INFYP_PLUGIN_URL . 'assets/css/plugin/seo-score-page.css',
            array(),
            INFYP_VERSION
        );""",
                "// Production: seo-score-page styles included in infyp.min.css bundle"
            ),
            # seo-score-references.css
            (
                """wp_enqueue_style(
            'infyp-seo-score-references',
            INFYP_PLUGIN_URL . 'assets/css/plugin/seo-score-references.css',
            array('infyp-seo-score-page'),
            INFYP_VERSION
        );""",
                "// Production: seo-score-references styles included in infyp.min.css bundle"
            ),
            # search-console.css
            (
                """wp_enqueue_style(
				'infyp-search-console',
				INFYP_PLUGIN_URL . 'assets/css/plugin/search-console.css',
				array(),
				INFYP_VERSION
			);""",
                "// Production: search-console styles included in infyp.min.css bundle"
            ),
            # debug-page.css
            (
                """wp_enqueue_style(
            'infyp-debug-page',
            INFYP_PLUGIN_URL . 'assets/css/plugin/debug-page.css',
            array(),
            INFYP_VERSION
        );""",
                "// Production: debug-page styles included in infyp.min.css bundle"
            ),
            # redirects page: api-usage.css (shared summary card grid)
            (
                """wp_enqueue_style(
				'infyp-api-usage',
				INFYP_PLUGIN_URL . 'assets/css/plugin/api-usage.css',
				array(),
				INFYP_VERSION
			);""",
                "// Production: api-usage styles included in infyp.min.css bundle"
            ),
            # redirects page: redirects.css
            (
                """wp_enqueue_style(
				'infyp-redirects',
				INFYP_PLUGIN_URL . 'assets/css/plugin/redirects.css',
				array( 'infyp-api-usage' ),
				INFYP_VERSION
			);""",
                "// Production: redirects styles included in infyp.min.css bundle"
            ),
        ]

        for old, new in css_replacements:
            admin_content = admin_content.replace(old, new)

        if admin_content != original_admin:
            admin_pages_path.write_text(admin_content, encoding='utf-8')
            print_success("Transformed INFYP_Admin_Pages.php for production CSS")

    # Transform INFYP_API_Usage_Page.php
    api_usage_path = build_path / 'includes' / 'Admin' / 'INFYP_API_Usage_Page.php'
    if api_usage_path.exists():
        api_content = api_usage_path.read_text(encoding='utf-8')
        original_api = api_content

        api_content = api_content.replace(
            "wp_enqueue_style( 'infyp-api-usage', INFYP_PLUGIN_URL . 'assets/css/plugin/api-usage.css', [], INFYP_VERSION );",
            "// Production: api-usage styles included in infyp.min.css bundle"
        )

        if api_content != original_api:
            api_usage_path.write_text(api_content, encoding='utf-8')
            print_success("Transformed INFYP_API_Usage_Page.php for production CSS")

    # Transform INFYP_REST_API_Page.php
    rest_api_path = build_path / 'includes' / 'Admin' / 'INFYP_REST_API_Page.php'
    if rest_api_path.exists():
        rest_content = rest_api_path.read_text(encoding='utf-8')
        original_rest = rest_content

        rest_content = rest_content.replace(
            "wp_enqueue_style( 'infyp-api-usage', INFYP_PLUGIN_URL . 'assets/css/plugin/api-usage.css', [], INFYP_VERSION );",
            "// Production: api-usage styles included in infyp.min.css bundle"
        )
        rest_content = rest_content.replace(
            "wp_enqueue_style( 'infyp-rest-api', INFYP_PLUGIN_URL . 'assets/css/plugin/rest-api.css', [ 'infyp-api-usage' ], INFYP_VERSION );",
            "// Production: rest-api styles included in infyp.min.css bundle"
        )

        if rest_content != original_rest:
            rest_api_path.write_text(rest_content, encoding='utf-8')
            print_success("Transformed INFYP_REST_API_Page.php for production CSS")


def transform_js_enqueues_for_production(build_path: Path) -> None:
    """
    Transform PHP files to use bundled JS instead of individual modules.

    In development, we load ES6 modules with import maps.
    In production, we load a single bundled infyp-admin.min.js file.
    """
    print_header("Transforming JS Enqueues for Production")

    script_manager_path = build_path / 'includes' / 'Core' / 'INFYP_Script_Manager.php'

    if not script_manager_path.exists():
        print_warning("INFYP_Script_Manager.php not found - skipping JS transform")
        return

    content = script_manager_path.read_text(encoding='utf-8')
    original_content = content

    # Replace admin-script.js with bundled version
    content = content.replace(
        "INFYP_PLUGIN_URL . 'assets/js/admin-script.js'",
        "INFYP_PLUGIN_URL . 'assets/js/dist/infyp-admin.min.js'"
    )

    # Replace standalone scripts with minified versions
    standalone_replacements = [
        ('assets/js/setup-wizard.js', 'assets/js/dist/setup-wizard.min.js'),
        ('assets/js/attachment-page.js', 'assets/js/dist/attachment-page.min.js'),
        ('assets/js/media-library.js', 'assets/js/dist/media-library.min.js'),
        ('assets/js/account-settings.js', 'assets/js/dist/account-settings.min.js'),
        ('assets/js/seo-score-page.js', 'assets/js/dist/seo-score-page.min.js'),
        ('assets/js/debug-page.js', 'assets/js/dist/debug-page.min.js'),
        ('assets/js/search-console.js', 'assets/js/dist/search-console.min.js'),
        ('assets/js/dist/dashboard.js', 'assets/js/dist/dashboard.min.js'),
        ('assets/js/dist/api-usage-chart.js', 'assets/js/dist/api-usage-chart.min.js'),
        ('assets/js/rest-api-chart.js', 'assets/js/dist/rest-api-chart.min.js'),
        ('assets/js/redirect-settings.js', 'assets/js/dist/redirect-settings.min.js'),
    ]

    for old_path, new_path in standalone_replacements:
        content = content.replace(old_path, new_path)

    # Remove the import map since bundled JS doesn't need it
    # Find and comment out the import map output in admin_head
    import_map_start = content.find("// Output import map for ES6 module resolution")
    if import_map_start != -1:
        import_map_end = content.find("</script>';", import_map_start)
        if import_map_end != -1:
            import_map_end += len("</script>';")
            old_block = content[import_map_start:import_map_end]
            new_block = "// Import map disabled in production - using bundled JS"
            content = content.replace(old_block, new_block)

    # Change script type from module to regular script for bundled version
    content = content.replace(
        "'type' => 'module'",
        "// 'type' => 'module' - disabled in production, using bundled IIFE"
    )

    if content != original_content:
        script_manager_path.write_text(content, encoding='utf-8')
        print_success("Transformed INFYP_Script_Manager.php for production JS")
    else:
        print_info("No JS enqueue changes needed")

    # Transform page-specific JS enqueues in INFYP_Admin_Pages.php
    admin_pages_path = build_path / 'includes' / 'Admin' / 'INFYP_Admin_Pages.php'
    if admin_pages_path.exists():
        admin_content = admin_pages_path.read_text(encoding='utf-8')
        original_admin = admin_content

        for old_path, new_path in standalone_replacements:
            admin_content = admin_content.replace(old_path, new_path)

        if admin_content != original_admin:
            admin_pages_path.write_text(admin_content, encoding='utf-8')
            print_success("Transformed INFYP_Admin_Pages.php for production JS")

    # Transform INFYP_Setup_Wizard.php
    wizard_path = build_path / 'includes' / 'Admin' / 'INFYP_Setup_Wizard.php'
    if wizard_path.exists():
        wizard_content = wizard_path.read_text(encoding='utf-8')
        original_wizard = wizard_content

        for old_path, new_path in standalone_replacements:
            wizard_content = wizard_content.replace(old_path, new_path)

        if wizard_content != original_wizard:
            wizard_path.write_text(wizard_content, encoding='utf-8')
            print_success("Transformed INFYP_Setup_Wizard.php for production JS")

    # Transform INFYP_API_Usage_Page.php JS enqueues
    api_usage_path = build_path / 'includes' / 'Admin' / 'INFYP_API_Usage_Page.php'
    if api_usage_path.exists():
        api_content = api_usage_path.read_text(encoding='utf-8')
        original_api = api_content

        for old_path, new_path in standalone_replacements:
            api_content = api_content.replace(old_path, new_path)

        if api_content != original_api:
            api_usage_path.write_text(api_content, encoding='utf-8')
            print_success("Transformed INFYP_API_Usage_Page.php for production JS")

    # Transform INFYP_REST_API_Page.php JS enqueues
    rest_api_path = build_path / 'includes' / 'Admin' / 'INFYP_REST_API_Page.php'
    if rest_api_path.exists():
        rest_content = rest_api_path.read_text(encoding='utf-8')
        original_rest = rest_content

        for old_path, new_path in standalone_replacements:
            rest_content = rest_content.replace(old_path, new_path)

        if rest_content != original_rest:
            rest_api_path.write_text(rest_content, encoding='utf-8')
            print_success("Transformed INFYP_REST_API_Page.php for production JS")

    # Transform INFYP_Redirect_Settings.php JS enqueues
    redirect_settings_path = build_path / 'includes' / 'Admin' / 'INFYP_Redirect_Settings.php'
    if redirect_settings_path.exists():
        redirect_content = redirect_settings_path.read_text(encoding='utf-8')
        original_redirect = redirect_content

        for old_path, new_path in standalone_replacements:
            redirect_content = redirect_content.replace(old_path, new_path)

        if redirect_content != original_redirect:
            redirect_settings_path.write_text(redirect_content, encoding='utf-8')
            print_success("Transformed INFYP_Redirect_Settings.php for production JS")


# =============================================================================
# File Copy and Build
# =============================================================================

def copy_plugin_files(source: Path, destination: Path, use_dist_css: bool = True, dev_mode: bool = False, submit_mode: bool = False) -> Tuple[int, int]:
    """Copy plugin files to build directory, excluding unwanted files.

    Args:
        source: Source directory path
        destination: Destination directory path
        use_dist_css: Whether to use dist assets
        dev_mode: Whether this is a development build
        submit_mode: If True, include WordPress.org assets (banners, screenshots, icons)
    """
    if submit_mode:
        print_header("Copying Plugin Files (WordPress.org Submission)")
    else:
        print_header("Copying Plugin Files")

    if destination.exists():
        shutil.rmtree(destination)

    destination.mkdir(parents=True)

    total_files = 0
    excluded_files = 0

    for root, dirs, files in os.walk(source):
        root_path = Path(root)

        # Filter out excluded directories
        dirs[:] = [d for d in dirs if not should_exclude(root_path / d, source, use_dist_css, submit_mode)]

        for file in files:
            file_path = root_path / file

            # Check exclusions
            if should_exclude(file_path, source, use_dist_css, submit_mode):
                # But check if it's a CSS or JS dist file we want to keep
                is_css_dist = use_dist_css and should_include_css_dist(file_path, source, dev_mode)
                is_js_dist = use_dist_css and should_include_js_dist(file_path, source, dev_mode)
                if not (is_css_dist or is_js_dist):
                    excluded_files += 1
                    continue

            # Copy file
            relative_path = file_path.relative_to(source)
            dest_path = destination / relative_path

            dest_path.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(file_path, dest_path)
            total_files += 1

    print_success(f"Copied {total_files} files ({excluded_files} excluded)")
    return total_files, excluded_files


def create_zip_archive(build_path: Path, version: str) -> Path:
    """Create the final zip archive."""
    print_header("Creating ZIP Archive")

    BUILD_DIR.mkdir(exist_ok=True)

    zip_filename = f'{PLUGIN_SLUG}-{version}.zip'
    zip_path = BUILD_DIR / zip_filename

    # Remove existing zip if present
    if zip_path.exists():
        zip_path.unlink()

    file_count = 0
    with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as zipf:
        for file_path in build_path.rglob('*'):
            if file_path.is_file():
                # Create archive path with plugin folder as root
                archive_path = Path(PLUGIN_SLUG) / file_path.relative_to(build_path)
                zipf.write(file_path, archive_path)
                file_count += 1

    # Get file size
    size_mb = zip_path.stat().st_size / (1024 * 1024)

    print_success(f"Created {zip_filename}")
    print_info(f"Size: {size_mb:.2f} MB ({file_count} files)")

    return zip_path


# =============================================================================
# Clean Command
# =============================================================================

def clean_build_artifacts() -> None:
    """Clean build artifacts."""
    print_header("Cleaning Build Artifacts")

    # Remove zip files in build directory
    zip_files = list(BUILD_DIR.glob('*.zip'))
    for zip_file in zip_files:
        zip_file.unlink()
        print(f"    Removed: {zip_file.name}")

    # Remove temp directories
    temp_dirs = list(Path(tempfile.gettempdir()).glob(f'{PLUGIN_SLUG}-build-*'))
    for temp_dir in temp_dirs:
        shutil.rmtree(temp_dir)
        print(f"    Removed: {temp_dir.name}")

    # Remove CSS dist files
    dist_dir = SOURCE_DIR / 'assets' / 'css' / 'dist'
    if dist_dir.exists():
        for f in dist_dir.glob('*'):
            f.unlink()
        print(f"    Cleaned: assets/css/dist/")

    print_success("Build artifacts cleaned")


# =============================================================================
# Main Build Process
# =============================================================================

def build_production(dev_mode: bool = False, submit_mode: bool = False) -> int:
    """Main production build process.

    Args:
        dev_mode: If True, skip minification
        submit_mode: If True, include WordPress.org assets (banners, screenshots, icons)
    """
    print()
    if submit_mode:
        print_color(Colors.BOLD + Colors.MAGENTA, "╔" + "═" * 60 + "╗")
        print_color(Colors.BOLD + Colors.MAGENTA, f"║  {'🚀 Infy - AI Image SEO Optimizer - WordPress.org Submission Build':<57} ║")
        print_color(Colors.BOLD + Colors.MAGENTA, "╚" + "═" * 60 + "╝")
    else:
        print_color(Colors.BOLD + Colors.BLUE, "╔" + "═" * 60 + "╗")
        print_color(Colors.BOLD + Colors.BLUE, f"║  {'🚀 Infy - AI Image SEO Optimizer - Production Build':<57} ║")
        print_color(Colors.BOLD + Colors.BLUE, "╚" + "═" * 60 + "╝")

    # Verify every version reference is in sync before building.
    if not check_version_consistency():
        return 1

    # Refuse to build while unknown files sit in the plugin root.
    if not check_root_entries():
        return 1

    # Get version
    version = get_plugin_version()
    if dev_mode:
        build_type = "Development"
    elif submit_mode:
        build_type = "WordPress.org Submission"
    else:
        build_type = "Production"

    print()
    print_info(f"Plugin version: {version}")
    print_info(f"Build type: {build_type}")
    print_info(f"Source: {SOURCE_DIR}")
    if submit_mode:
        print_info("Including: Banners, Screenshots, Icons")

    # Create temp directory
    temp_dir = Path(tempfile.gettempdir()) / f'{PLUGIN_SLUG}-build-{datetime.now().strftime("%Y%m%d%H%M%S")}'

    try:
        # Step 0: Regenerate Composer autoloader so newly added classes are
        # always in the shipped classmap (prevents "class not found" fatals).
        if not regenerate_autoloader():
            print_error("Autoloader regeneration failed!")
            return 1

        # Step 1: Build CSS
        if not build_css(minify=not dev_mode):
            print_error("CSS build failed!")
            return 1

        # Step 2: Build JavaScript (bundles + minified standalone)
        if not build_javascript(minify=not dev_mode):
            print_error("JavaScript build failed!")
            return 1

        # Step 3: Copy files
        copy_plugin_files(SOURCE_DIR, temp_dir, use_dist_css=True, dev_mode=dev_mode, submit_mode=submit_mode)

        # Step 4: Transform PHP enqueues for production
        if not dev_mode:
            transform_css_enqueues_for_production(temp_dir)
            transform_js_enqueues_for_production(temp_dir)

        # Step 5: Create zip
        zip_path = create_zip_archive(temp_dir, version)

        # Cleanup
        print_header("Cleanup")
        shutil.rmtree(temp_dir)
        print_success("Temporary files cleaned")

        # Summary
        print()
        print_color(Colors.BOLD + Colors.GREEN, "╔" + "═" * 60 + "╗")
        print_color(Colors.BOLD + Colors.GREEN, f"║  {'✅ Build Complete!':<57} ║")
        print_color(Colors.BOLD + Colors.GREEN, "╚" + "═" * 60 + "╝")
        print()
        print_color(Colors.CYAN, f"  📦 Package: {zip_path}")
        print_color(Colors.CYAN, f"  📏 Size: {zip_path.stat().st_size / (1024 * 1024):.2f} MB")
        print()

        return 0

    except Exception as e:
        print_error(f"Build failed: {e}")

        # Cleanup on error
        if temp_dir.exists():
            shutil.rmtree(temp_dir)

        return 1


# =============================================================================
# CLI Interface
# =============================================================================

def main():
    """Main entry point."""
    parser = argparse.ArgumentParser(
        description='Infy - AI Image SEO Optimizer - Production Build Script',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python3 build.py              Build production-ready plugin (excludes WP.org assets)
  python3 build.py --submit     Build for WordPress.org (includes banners/screenshots)
  python3 build.py --dev        Build without minification
  python3 build.py --css-only   Only rebuild CSS assets
  python3 build.py --clean      Clean build artifacts
  python3 build.py --check      Check versions and the root allow-list (no build)
  python3 build.py --bump=1.0.1 Bump version across plugin header, INFYP_VERSION,
                                readme.txt Stable tag, package.json, package-lock.json
                                and CHANGELOG.md, and prepend changelog stubs
        """
    )

    parser.add_argument(
        '--bump',
        metavar='X.Y.Z',
        help='Bump plugin version across all authoritative locations, then exit'
    )

    parser.add_argument(
        '--check',
        action='store_true',
        help='Check version consistency and the plugin root allow-list, then exit (no build)'
    )

    parser.add_argument(
        '--submit', '-s',
        action='store_true',
        help='WordPress.org submission build (includes banners, screenshots, icons)'
    )

    parser.add_argument(
        '--dev', '-d',
        action='store_true',
        help='Development build (no minification)'
    )

    parser.add_argument(
        '--css-only', '-c',
        action='store_true',
        help='Only rebuild CSS assets'
    )

    parser.add_argument(
        '--clean',
        action='store_true',
        help='Clean build artifacts'
    )

    parser.add_argument(
        '--version', '-v',
        action='store_true',
        help='Show plugin version'
    )

    args = parser.parse_args()

    # Handle commands
    if args.version:
        print(f"Infy - AI Image SEO Optimizer v{get_plugin_version()}")
        return 0

    if args.bump:
        return bump_version(args.bump)

    if args.check:
        versions_ok = check_version_consistency()
        root_ok = check_root_entries()
        if versions_ok and root_ok:
            print_success(f"Versions in sync ({get_plugin_version()}); plugin root matches the allow-lists")
            return 0
        return 1

    if args.clean:
        clean_build_artifacts()
        return 0

    if args.css_only:
        return 0 if build_css(minify=not args.dev) else 1

    # Full build
    return build_production(dev_mode=args.dev, submit_mode=args.submit)


if __name__ == '__main__':
    sys.exit(main())
