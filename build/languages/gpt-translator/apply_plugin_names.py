#!/usr/bin/env python3
"""
Apply plugin name translations from plugin_name_translations.py into .po files.

This script:
1. Reads the researched translations from plugin_name_translations.py
2. Finds the matching msgid entries in each .po file
3. Sets the correct msgstr for "AI Image SEO Optimizer" and "AI Image SEO"
4. Compiles .mo files

Usage:
    python3 apply_plugin_names.py              # Apply to all languages
    python3 apply_plugin_names.py de_DE fr_FR  # Apply to specific languages
    python3 apply_plugin_names.py --dry-run    # Preview without changing files
    python3 apply_plugin_names.py --compile    # Also compile .mo files after
"""

import sys
import subprocess
from pathlib import Path

try:
    import polib
except ImportError:
    print("Error: polib required. Install with: pip install polib")
    sys.exit(1)

from plugin_name_translations import PLUGIN_NAME_TRANSLATIONS

# Paths
SCRIPT_DIR = Path(__file__).parent
PLUGIN_DIR = SCRIPT_DIR.parent.parent.parent  # up to plugin root
LANG_DIR = PLUGIN_DIR / 'languages'
PLUGIN_SLUG = 'infy-ai-image-seo-optimizer'

# The English source strings we're translating
SOURCE_STRINGS = {
    'AI Image SEO Optimizer': 'page_title',
    'AI Image SEO': 'menu_label',
}


def apply_translations(locales=None, dry_run=False, compile_mo=False):
    """Apply plugin name translations to .po files."""

    if locales is None:
        locales = list(PLUGIN_NAME_TRANSLATIONS.keys())

    print(f"\n{'=' * 60}")
    print(f"  Apply Plugin Name Translations")
    print(f"{'=' * 60}")
    print(f"  Languages: {len(locales)}")
    print(f"  Dry run: {dry_run}")
    print(f"  Compile .mo: {compile_mo}")
    print(f"{'=' * 60}\n")

    updated_count = 0
    skipped_count = 0
    error_count = 0

    for locale in sorted(locales):
        if locale not in PLUGIN_NAME_TRANSLATIONS:
            print(f"  SKIP {locale} — not in plugin_name_translations.py")
            skipped_count += 1
            continue

        po_file = LANG_DIR / f'{PLUGIN_SLUG}-{locale}.po'
        if not po_file.exists():
            print(f"  SKIP {locale} — .po file not found: {po_file.name}")
            skipped_count += 1
            continue

        translations = PLUGIN_NAME_TRANSLATIONS[locale]

        try:
            po = polib.pofile(str(po_file))
            changes = 0

            for msgid, field in SOURCE_STRINGS.items():
                target = translations.get(field, '')
                if not target:
                    continue

                for entry in po:
                    if entry.msgid == msgid:
                        old = entry.msgstr
                        if old == target:
                            break  # Already correct

                        if dry_run:
                            print(f"  {locale:8s} | {msgid:30s}")
                            print(f"           | OLD: {old or '(empty)'}")
                            print(f"           | NEW: {target}")
                        else:
                            entry.msgstr = target
                            changes += 1
                        break

            if changes > 0 and not dry_run:
                po.save()
                print(f"  ✅ {locale:8s} | {translations.get('page_title', '')}")
                print(f"           | {translations.get('menu_label', '')}")
                updated_count += 1

                # Compile .mo
                if compile_mo:
                    mo_file = po_file.with_suffix('.mo')
                    po.save_as_mofile(str(mo_file))

            elif changes == 0 and not dry_run:
                # Check if already correct
                already_correct = True
                for msgid, field in SOURCE_STRINGS.items():
                    target = translations.get(field, '')
                    for entry in po:
                        if entry.msgid == msgid and entry.msgstr != target:
                            already_correct = False
                            break

                if already_correct:
                    skipped_count += 1
                else:
                    # Entries not found in .po file
                    print(f"  ⚠️  {locale:8s} | msgid not found in .po file")
                    error_count += 1

        except Exception as e:
            print(f"  ❌ {locale:8s} | Error: {e}")
            error_count += 1

    print(f"\n{'=' * 60}")
    print(f"  Summary")
    print(f"{'=' * 60}")
    print(f"  Updated:  {updated_count}")
    print(f"  Skipped:  {skipped_count} (already correct or missing)")
    print(f"  Errors:   {error_count}")
    if compile_mo:
        print(f"  .mo files compiled: {updated_count}")
    print(f"{'=' * 60}\n")

    if not dry_run and not compile_mo and updated_count > 0:
        print("  NOTE: .mo files not compiled. Run with --compile or:")
        print(f"  python3 apply_plugin_names.py --compile\n")


if __name__ == '__main__':
    args = sys.argv[1:]

    dry_run = '--dry-run' in args
    compile_mo = '--compile' in args

    # Filter out flags to get locale list
    locales = [a for a in args if not a.startswith('--')]

    if not locales:
        locales = None  # All languages

    apply_translations(locales=locales, dry_run=dry_run, compile_mo=compile_mo)
