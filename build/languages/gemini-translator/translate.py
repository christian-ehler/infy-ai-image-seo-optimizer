#!/usr/bin/env python3
"""
AI Image SEO Optimizer - Unified Translation System (Gemini)

Uses Google Gemini 3 Flash with MEDIUM thinking for high-quality translations.

One script, one command. Handles every translation workflow:
  - Generate POT from PHP source
  - Update PO files with new strings
  - Translate untranslated strings for all languages
  - Fix English fallbacks
  - Full retranslation of specific languages
  - Show translation status/stats

Usage:
    python3 translate.py                          # Full workflow: POT -> update PO -> translate -> compile
    python3 translate.py --languages de_DE es_ES  # Only specific languages
    python3 translate.py --skip-pot               # Skip POT regeneration
    python3 translate.py --dry-run                # Preview without API calls
    python3 translate.py --fix                    # Clear English fallbacks first, then translate
    python3 translate.py --full es_ES             # Full retranslation of a language (all strings)
    python3 translate.py --generate-pot           # Only generate POT, no translation
    python3 translate.py --status                 # Show translation stats for all languages
    python3 translate.py --batch-size 50          # Custom batch size
"""

import os
import sys
import json
import re
import argparse
import time
import requests
from typing import Dict, List, Optional, Set, Tuple
from datetime import datetime
from pathlib import Path

# Load .env file if present
env_file = Path(__file__).parent / '.env'
if env_file.exists():
    with open(env_file) as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith('#') and '=' in line:
                key, value = line.split('=', 1)
                os.environ.setdefault(key.strip(), value.strip())

try:
    import polib
except ImportError:
    print("Error: polib is required. Install it with: pip install polib")
    sys.exit(1)


# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

GEMINI_API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models'

BRAND_NAMES = {
    'AI Image SEO Optimizer',
    'Infy - AI Image SEO Optimizer',
    'Anthropic Claude',
    'Anthropic Claude API',
    'Google Gemini',
    'OpenAI',
    'OpenAI GPT',
    'OpenAI GPT API',
    'Elementor',
    'Beaver Builder',
    'Bricks Builder',
    'Divi Builder',
    'Fusion Builder',
    'Oxygen Builder',
    'SiteOrigin',
    'Themify Builder',
    'WPBakery',
    'Uncode',
    'Google Search Console',
    'support@infypress.com',
}


def is_untranslatable(msgid: str) -> bool:
    """Check if a string should stay the same in all languages."""
    if msgid in BRAND_NAMES:
        return True
    if len(msgid) <= 3:
        return True
    if re.match(r'^[\d\s,.\-\u2013+]+(\s*(bytes|KB|MB|GB))?$', msgid):
        return True
    stripped = re.sub(r'%[0-9]*\$?[sd]', '', msgid)
    if not stripped.strip(' :,.-'):
        return True
    return False


def is_english_fallback(msgid: str, msgstr: str, lang_code: str) -> bool:
    """Detect if a translation is actually an English fallback."""
    if lang_code.startswith('en_'):
        return False
    if msgstr != msgid:
        return False
    if is_untranslatable(msgid):
        return False
    return True


# ---------------------------------------------------------------------------
# POTGenerator
# ---------------------------------------------------------------------------

class POTGenerator:
    """Generates .pot files by extracting translatable strings from WordPress PHP files."""

    I18N_FUNCTIONS = [
        r'__\s*\(\s*["\'](.+?)["\']\s*,\s*["\']([^"\']+)["\']\s*\)',
        r'_e\s*\(\s*["\'](.+?)["\']\s*,\s*["\']([^"\']+)["\']\s*\)',
        r'esc_html__\s*\(\s*["\'](.+?)["\']\s*,\s*["\']([^"\']+)["\']\s*\)',
        r'esc_html_e\s*\(\s*["\'](.+?)["\']\s*,\s*["\']([^"\']+)["\']\s*\)',
        r'esc_attr__\s*\(\s*["\'](.+?)["\']\s*,\s*["\']([^"\']+)["\']\s*\)',
        r'esc_attr_e\s*\(\s*["\'](.+?)["\']\s*,\s*["\']([^"\']+)["\']\s*\)',
        r'_x\s*\(\s*["\'](.+?)["\']\s*,\s*["\'][^"\']*["\']\s*,\s*["\']([^"\']+)["\']\s*\)',
        r'_ex\s*\(\s*["\'](.+?)["\']\s*,\s*["\'][^"\']*["\']\s*,\s*["\']([^"\']+)["\']\s*\)',
        r'esc_html_x\s*\(\s*["\'](.+?)["\']\s*,\s*["\'][^"\']*["\']\s*,\s*["\']([^"\']+)["\']\s*\)',
        r'esc_attr_x\s*\(\s*["\'](.+?)["\']\s*,\s*["\'][^"\']*["\']\s*,\s*["\']([^"\']+)["\']\s*\)',
        r'_n\s*\(\s*["\'](.+?)["\']\s*,\s*["\'](.+?)["\']\s*,\s*[^,]+,\s*["\']([^"\']+)["\']\s*\)',
        r'_nx\s*\(\s*["\'](.+?)["\']\s*,\s*["\'](.+?)["\']\s*,\s*[^,]+,\s*["\'][^"\']*["\']\s*,\s*["\']([^"\']+)["\']\s*\)',
    ]

    def __init__(self, plugin_dir: str, text_domain: str = 'infy-ai-image-seo-optimizer'):
        self.plugin_dir = Path(plugin_dir)
        self.text_domain = text_domain
        self.strings: Dict[str, List[Tuple[str, int]]] = {}
        self.plural_strings: Dict[str, Tuple[str, List[Tuple[str, int]]]] = {}

    def scan_directory(self, exclude_dirs: List[str] = None) -> int:
        return self.scan_directory_path(self.plugin_dir, exclude_dirs)

    def scan_directory_path(self, directory: Path, exclude_dirs: List[str] = None,
                            exclude_files: List[str] = None) -> int:
        if exclude_dirs is None:
            exclude_dirs = ['vendor', 'node_modules', 'tests', 'translation-report', 'build']
        if exclude_files is None:
            exclude_files = ['pro-page.php', 'pro-page-backup.php']

        print(f"  Scanning {directory} for translatable strings...")
        print(f"  Excluding dirs: {', '.join(exclude_dirs)}")
        print(f"  Excluding files: {', '.join(exclude_files)}")

        initial_string_count = len(self.strings)
        initial_plural_count = len(self.plural_strings)

        php_files = []
        for php_file in Path(directory).rglob('*.php'):
            if any(excluded in php_file.parts for excluded in exclude_dirs):
                continue
            if php_file.name in exclude_files:
                continue
            php_files.append(php_file)

        print(f"  Found {len(php_files)} PHP files")

        for php_file in php_files:
            self._extract_from_file(php_file)

        new_strings = (len(self.strings) - initial_string_count) + (len(self.plural_strings) - initial_plural_count)
        total_strings = len(self.strings) + len(self.plural_strings)

        print(f"  Extraction complete: {total_strings} unique strings ({len(self.strings)} regular, {len(self.plural_strings)} plural)")
        return new_strings

    def _extract_from_file(self, file_path: Path):
        try:
            with open(file_path, 'r', encoding='utf-8') as f:
                content = f.read()
        except Exception as e:
            print(f"  Warning: Could not read {file_path}: {e}")
            return

        try:
            rel_path = file_path.relative_to(self.plugin_dir)
        except ValueError:
            rel_path = file_path

        for pattern in self.I18N_FUNCTIONS[:10]:
            for match in re.finditer(pattern, content, re.MULTILINE | re.DOTALL):
                msgid = match.group(1)
                text_domain = match.group(2) if match.lastindex >= 2 else self.text_domain
                if text_domain != self.text_domain:
                    continue
                line_num = content[:match.start()].count('\n') + 1
                msgid = self._unescape_string(msgid)
                if msgid not in self.strings:
                    self.strings[msgid] = []
                self.strings[msgid].append((str(rel_path), line_num))

        for pattern in self.I18N_FUNCTIONS[10:]:
            for match in re.finditer(pattern, content, re.MULTILINE | re.DOTALL):
                msgid = match.group(1)
                msgid_plural = match.group(2)
                text_domain = match.group(3) if match.lastindex >= 3 else self.text_domain
                if text_domain != self.text_domain:
                    continue
                line_num = content[:match.start()].count('\n') + 1
                msgid = self._unescape_string(msgid)
                msgid_plural = self._unescape_string(msgid_plural)
                if msgid not in self.plural_strings:
                    self.plural_strings[msgid] = (msgid_plural, [])
                self.plural_strings[msgid][1].append((str(rel_path), line_num))

    def _unescape_string(self, s: str) -> str:
        s = s.replace(r'\"', '"')
        s = s.replace(r"\'", "'")
        s = s.replace(r'\\', '\\')
        return s

    def generate_pot_file(self, output_path: str, metadata: Dict[str, str] = None) -> str:
        pot = polib.POFile()
        if metadata is None:
            metadata = {}

        pot.metadata = {
            'Project-Id-Version': metadata.get('Project-Id-Version', 'AI Image SEO Optimizer 1.0.0'),
            'Report-Msgid-Bugs-To': metadata.get('Report-Msgid-Bugs-To', 'https://wordpress.org/support/plugin/infy-ai-image-seo-optimizer'),
            'POT-Creation-Date': datetime.now().strftime('%Y-%m-%d %H:%M+0000'),
            'PO-Revision-Date': 'YEAR-MO-DA HO:MI+ZONE',
            'Last-Translator': 'AI Image SEO Optimizer Translation Team <noreply@infypress.com>',
            'Language-Team': 'AI Image SEO Optimizer Translation Team <noreply@infypress.com>',
            'MIME-Version': '1.0',
            'Content-Type': 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding': '8bit',
            'X-Domain': self.text_domain,
            'X-Generator': 'Infy POT Generator (Python)',
        }

        for msgid, occurrences in sorted(self.strings.items()):
            entry = polib.POEntry(
                msgid=msgid,
                msgstr='',
                occurrences=[(file, line) for file, line in occurrences]
            )
            pot.append(entry)

        for msgid, (msgid_plural, occurrences) in sorted(self.plural_strings.items()):
            entry = polib.POEntry(
                msgid=msgid,
                msgid_plural=msgid_plural,
                msgstr_plural={0: '', 1: ''},
                occurrences=[(file, line) for file, line in occurrences]
            )
            pot.append(entry)

        pot.save(output_path)
        print(f"  Saved .pot file: {output_path}")
        print(f"  Total entries: {len(pot)} ({len(self.strings)} regular, {len(self.plural_strings)} plural)")
        return output_path


# ---------------------------------------------------------------------------
# GeminiTranslator
# ---------------------------------------------------------------------------

class GeminiTranslator:
    """Translates WordPress plugin strings using Google Gemini 3 Flash with regional context awareness."""

    LANGUAGE_CONFIGS = {
        # Spanish variants
        "es_ES": {
            "name": "Spanish (Spain)",
            "context": "Use Castilian Spanish as spoken in Spain. Use 'vosotros' forms. Use European Spanish terminology.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "es_MX": {
            "name": "Spanish (Mexico)",
            "context": "Use Mexican Spanish with local expressions and vocabulary common in Mexico. Use 'ustedes' instead of 'vosotros'. Adapt to Mexican technological and business terminology.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "es_AR": {
            "name": "Spanish (Argentina)",
            "context": "Use Argentine Spanish with 'vos' forms and local expressions. Use Argentine terminology and idioms. Adapt to Rioplatense Spanish.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "es_CO": {
            "name": "Spanish (Colombia)",
            "context": "Use Colombian Spanish with local expressions and vocabulary. Use 'usted' respectfully in formal contexts. Adapt to Colombian terminology.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "es_CL": {
            "name": "Spanish (Chile)",
            "context": "Use Chilean Spanish with local expressions and vocabulary. Adapt to Chilean idioms and terminology.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "es_PE": {
            "name": "Spanish (Peru)",
            "context": "Use Peruvian Spanish with local expressions. Adapt to Peruvian terminology and idioms.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "es_VE": {
            "name": "Spanish (Venezuela)",
            "context": "Use Venezuelan Spanish with local expressions and vocabulary. Adapt to Venezuelan idioms.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "es_GT": {
            "name": "Spanish (Guatemala)",
            "context": "Use Guatemalan Spanish with local expressions. Adapt to Central American terminology.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "es_CR": {
            "name": "Spanish (Costa Rica)",
            "context": "Use Costa Rican Spanish with local expressions and 'usted' for formal contexts. Adapt to Central American terminology common in Costa Rica.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "es_EC": {
            "name": "Spanish (Ecuador)",
            "context": "Use Ecuadorian Spanish with local expressions and vocabulary. Adapt to Andean Spanish terminology.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "es_DO": {
            "name": "Spanish (Dominican Republic)",
            "context": "Use Dominican Spanish with Caribbean expressions and local vocabulary. Adapt to Dominican idioms and terminology.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "es_UY": {
            "name": "Spanish (Uruguay)",
            "context": "Use Uruguayan Spanish with 'vos' forms and local expressions similar to Argentina. Adapt to Rioplatense Spanish as spoken in Uruguay.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "es_PR": {
            "name": "Spanish (Puerto Rico)",
            "context": "Use Puerto Rican Spanish with Caribbean expressions and vocabulary. Adapt to local idioms common in Puerto Rico.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # German variants
        "de_DE": {
            "name": "German (Germany)",
            "context": "Use German (Du form) as spoken in Germany. Use standard German spelling and grammar.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "de_AT": {
            "name": "German (Austria)",
            "context": "Use Austrian German (Du form) with local expressions and vocabulary. Adapt to Austrian terminology (e.g., 'Jänner' instead of 'Januar').",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "de_CH": {
            "name": "German (Switzerland)",
            "context": "Use Swiss Standard German (Sie form). Use Swiss terminology and avoid the ß character (use ss instead).",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "de_CH_informal": {
            "name": "German (Switzerland - Informal)",
            "context": "Use informal Swiss German (du form). Use Swiss terminology and avoid the ß character.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "de_DE_formal": {
            "name": "German (Germany - Formal)",
            "context": "Use very formal German with Sie form exclusively. Professional business tone.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # English variants
        "en_GB": {
            "name": "English (UK)",
            "context": "Use British English spelling and vocabulary (e.g., 'optimise', 'colour', 'analyse'). Use British terminology.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "en_AU": {
            "name": "English (Australia)",
            "context": "Use Australian English with British spelling but Australian expressions and terminology.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "en_CA": {
            "name": "English (Canada)",
            "context": "Use Canadian English which mixes British and American spelling. Use Canadian terminology.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "en_NZ": {
            "name": "English (New Zealand)",
            "context": "Use New Zealand English with British spelling and Kiwi expressions.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "en_ZA": {
            "name": "English (South Africa)",
            "context": "Use South African English with British spelling and local expressions.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Portuguese variants
        "pt_BR": {
            "name": "Portuguese (Brazil)",
            "context": "Use Brazilian Portuguese with Brazilian vocabulary and expressions. Use 'você' form.",
            "plural_forms": "nplurals=2; plural=(n > 1);"
        },
        "pt_PT": {
            "name": "Portuguese (Portugal)",
            "context": "Use European Portuguese as spoken in Portugal. Use formal 'você' or 'tu' appropriately.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "pt_PT_ao90": {
            "name": "Portuguese (Portugal - AO90)",
            "context": "Use European Portuguese with the Acordo Ortográfico de 1990 spelling reform. Modern Portuguese orthography used in Portugal since 2009.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "pt_AO": {
            "name": "Portuguese (Angola)",
            "context": "Use Angolan Portuguese with local expressions and African Portuguese vocabulary. Adapt to Angolan terminology and idioms.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Polish
        "pl_PL": {
            "name": "Polish (Poland)",
            "context": "Use standard Polish as spoken in Poland. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);"
        },
        # French variants
        "fr_FR": {
            "name": "French (France)",
            "context": "Use standard French as spoken in France. Use formal register.",
            "plural_forms": "nplurals=2; plural=(n > 1);"
        },
        "fr_CA": {
            "name": "French (Canada)",
            "context": "Use Canadian French with Quebec expressions and terminology. Adapt to Canadian French vocabulary.",
            "plural_forms": "nplurals=2; plural=(n > 1);"
        },
        "fr_BE": {
            "name": "French (Belgium)",
            "context": "Use Belgian French with local expressions and terminology.",
            "plural_forms": "nplurals=2; plural=(n > 1);"
        },
        # Italian
        "it_IT": {
            "name": "Italian (Italy)",
            "context": "Use standard Italian as spoken in Italy.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Dutch variants
        "nl_NL": {
            "name": "Dutch (Netherlands)",
            "context": "Use standard Dutch as spoken in the Netherlands.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "nl_NL_formal": {
            "name": "Dutch (Netherlands - Formal)",
            "context": "Use formal Dutch with 'u' form exclusively. Professional and respectful tone appropriate for business contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "nl_BE": {
            "name": "Dutch (Belgium)",
            "context": "Use Flemish (Belgian Dutch) with local expressions and terminology.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Danish
        "da_DK": {
            "name": "Danish (Denmark)",
            "context": "Use standard Danish as spoken in Denmark. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Afrikaans
        "af": {
            "name": "Afrikaans",
            "context": "Use standard Afrikaans. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Amharic
        "am": {
            "name": "Amharic",
            "context": "Use standard Amharic as spoken in Ethiopia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n > 1);"
        },
        # Aragonese
        "arg": {
            "name": "Aragonese",
            "context": "Use Aragonese language as spoken in Aragon, Spain. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Arabic variants
        "ar": {
            "name": "Arabic",
            "context": "Use Modern Standard Arabic. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 && n%100<=99 ? 4 : 5);"
        },
        "ary": {
            "name": "Moroccan Arabic",
            "context": "Use Moroccan Arabic dialect with local expressions and vocabulary. Adapt to Moroccan terminology.",
            "plural_forms": "nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 && n%100<=99 ? 4 : 5);"
        },
        # Assamese
        "as": {
            "name": "Assamese",
            "context": "Use standard Assamese as spoken in Assam, India. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Azerbaijani variants
        "azb": {
            "name": "South Azerbaijani",
            "context": "Use South Azerbaijani (Iranian Azerbaijani) with Perso-Arabic script. Use formal register.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "az": {
            "name": "Azerbaijani",
            "context": "Use North Azerbaijani as spoken in Azerbaijan with Latin script. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Belarusian
        "bel": {
            "name": "Belarusian",
            "context": "Use standard Belarusian. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);"
        },
        # Bulgarian
        "bg_BG": {
            "name": "Bulgarian",
            "context": "Use standard Bulgarian as spoken in Bulgaria. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Bengali
        "bn_BD": {
            "name": "Bengali (Bangladesh)",
            "context": "Use Bengali as spoken in Bangladesh. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Tibetan
        "bo": {
            "name": "Tibetan",
            "context": "Use standard Tibetan. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Bosnian
        "bs_BA": {
            "name": "Bosnian",
            "context": "Use standard Bosnian as spoken in Bosnia and Herzegovina. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);"
        },
        # Catalan
        "ca": {
            "name": "Catalan",
            "context": "Use standard Catalan as spoken in Catalonia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Cebuano
        "ceb": {
            "name": "Cebuano",
            "context": "Use Cebuano as spoken in the Philippines. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Czech
        "cs_CZ": {
            "name": "Czech",
            "context": "Use standard Czech as spoken in Czech Republic. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;"
        },
        # Welsh
        "cy": {
            "name": "Welsh",
            "context": "Use standard Welsh as spoken in Wales. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=4; plural=(n==1) ? 0 : (n==2) ? 1 : (n != 8 && n != 11) ? 2 : 3;"
        },
        # Lower Sorbian
        "dsb": {
            "name": "Lower Sorbian",
            "context": "Use Lower Sorbian as spoken in Brandenburg, Germany. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=4; plural=(n%100==1 ? 0 : n%100==2 ? 1 : n%100==3 || n%100==4 ? 2 : 3);"
        },
        # Dzongkha
        "dzo": {
            "name": "Dzongkha",
            "context": "Use Dzongkha, the national language of Bhutan. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Greek
        "el": {
            "name": "Greek",
            "context": "Use Modern Greek as spoken in Greece. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Esperanto
        "eo": {
            "name": "Esperanto",
            "context": "Use standard Esperanto, the international auxiliary language. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Estonian
        "et": {
            "name": "Estonian",
            "context": "Use standard Estonian as spoken in Estonia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Basque
        "eu": {
            "name": "Basque",
            "context": "Use standard Basque (Euskera batua). Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Persian/Farsi variants
        "fa_AF": {
            "name": "Persian (Afghanistan)",
            "context": "Use Dari Persian as spoken in Afghanistan. Use formal register appropriate for business and technical contexts. Adapt to Afghan terminology and expressions.",
            "plural_forms": "nplurals=2; plural=(n > 1);"
        },
        "fa_IR": {
            "name": "Persian (Iran)",
            "context": "Use standard Persian as spoken in Iran. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n > 1);"
        },
        # Finnish
        "fi": {
            "name": "Finnish",
            "context": "Use standard Finnish as spoken in Finland. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Friulian
        "fur": {
            "name": "Friulian",
            "context": "Use Friulian as spoken in Friuli-Venezia Giulia, Italy. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Western Frisian
        "fy": {
            "name": "Western Frisian",
            "context": "Use Western Frisian as spoken in Friesland, Netherlands. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Scottish Gaelic
        "gd": {
            "name": "Scottish Gaelic",
            "context": "Use Scottish Gaelic as spoken in Scotland. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=4; plural=(n==1 || n==11) ? 0 : (n==2 || n==12) ? 1 : (n > 2 && n < 20) ? 2 : 3;"
        },
        # Galician
        "gl_ES": {
            "name": "Galician",
            "context": "Use standard Galician as spoken in Galicia, Spain. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Gujarati
        "gu": {
            "name": "Gujarati",
            "context": "Use standard Gujarati as spoken in Gujarat, India. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Hazaragi
        "haz": {
            "name": "Hazaragi",
            "context": "Use Hazaragi, a dialect of Persian spoken by the Hazara people in Afghanistan and Pakistan. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n > 1);"
        },
        # Hebrew
        "he_IL": {
            "name": "Hebrew",
            "context": "Use Modern Hebrew as spoken in Israel. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Hindi
        "hi_IN": {
            "name": "Hindi",
            "context": "Use standard Hindi as spoken in India. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Croatian
        "hr": {
            "name": "Croatian",
            "context": "Use standard Croatian as spoken in Croatia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);"
        },
        # Upper Sorbian
        "hsb": {
            "name": "Upper Sorbian",
            "context": "Use Upper Sorbian as spoken in Saxony, Germany. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=4; plural=(n%100==1 ? 0 : n%100==2 ? 1 : n%100==3 || n%100==4 ? 2 : 3);"
        },
        # Hungarian
        "hu_HU": {
            "name": "Hungarian",
            "context": "Use standard Hungarian as spoken in Hungary. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Armenian
        "hy": {
            "name": "Armenian",
            "context": "Use Eastern Armenian as spoken in Armenia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Indonesian
        "id_ID": {
            "name": "Indonesian",
            "context": "Use standard Indonesian as the official language of Indonesia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Icelandic
        "is_IS": {
            "name": "Icelandic",
            "context": "Use standard Icelandic as spoken in Iceland. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n%10!=1 || n%100==11);"
        },
        # Japanese
        "ja": {
            "name": "Japanese",
            "context": "Use standard Japanese. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Javanese
        "jv_ID": {
            "name": "Javanese",
            "context": "Use Javanese as spoken in Java, Indonesia. Use formal register (Krama) appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 0);"
        },
        # Georgian
        "ka_GE": {
            "name": "Georgian",
            "context": "Use standard Georgian as spoken in Georgia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Kabyle
        "kab": {
            "name": "Kabyle",
            "context": "Use Kabyle, a Berber language spoken in Algeria. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Kazakh
        "kk": {
            "name": "Kazakh",
            "context": "Use standard Kazakh as spoken in Kazakhstan. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Khmer
        "km": {
            "name": "Khmer",
            "context": "Use Central Khmer as spoken in Cambodia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Kannada
        "kn": {
            "name": "Kannada",
            "context": "Use standard Kannada as spoken in Karnataka, India. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Korean
        "ko_KR": {
            "name": "Korean",
            "context": "Use standard Korean as spoken in South Korea. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Central Kurdish (Sorani)
        "ckb": {
            "name": "Central Kurdish",
            "context": "Use Central Kurdish (Sorani) as spoken in Iraq and Iran. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Kyrgyz
        "kir": {
            "name": "Kyrgyz",
            "context": "Use standard Kyrgyz as spoken in Kyrgyzstan. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Lao
        "lo": {
            "name": "Lao",
            "context": "Use standard Lao as spoken in Laos. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Lithuanian
        "lt_LT": {
            "name": "Lithuanian",
            "context": "Use standard Lithuanian as spoken in Lithuania. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && (n%100<10 || n%100>=20) ? 1 : 2);"
        },
        # Latvian
        "lv": {
            "name": "Latvian",
            "context": "Use standard Latvian as spoken in Latvia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n != 0 ? 1 : 2);"
        },
        # Macedonian
        "mk_MK": {
            "name": "Macedonian",
            "context": "Use standard Macedonian as spoken in North Macedonia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n==1 || n%10==1 ? 0 : 1);"
        },
        # Malayalam
        "ml_IN": {
            "name": "Malayalam",
            "context": "Use standard Malayalam as spoken in Kerala, India. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Mongolian
        "mn": {
            "name": "Mongolian",
            "context": "Use standard Mongolian as spoken in Mongolia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Marathi
        "mr": {
            "name": "Marathi",
            "context": "Use standard Marathi as spoken in Maharashtra, India. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Malay
        "ms_MY": {
            "name": "Malay",
            "context": "Use standard Malay as spoken in Malaysia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Burmese
        "my_MM": {
            "name": "Burmese",
            "context": "Use standard Burmese as spoken in Myanmar. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Norwegian Bokmal
        "nb_NO": {
            "name": "Norwegian Bokmal",
            "context": "Use Norwegian Bokmal as spoken in Norway. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Nepali
        "ne_NP": {
            "name": "Nepali",
            "context": "Use standard Nepali as spoken in Nepal. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Norwegian Nynorsk
        "nn_NO": {
            "name": "Norwegian Nynorsk",
            "context": "Use Norwegian Nynorsk as spoken in Norway. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Occitan
        "oci": {
            "name": "Occitan",
            "context": "Use standard Occitan (Languedocian variant preferred). Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n > 1);"
        },
        # Punjabi
        "pa_IN": {
            "name": "Punjabi",
            "context": "Use Eastern Punjabi (Gurmukhi script) as spoken in Punjab, India. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Pashto
        "ps": {
            "name": "Pashto",
            "context": "Use standard Pashto as spoken in Afghanistan and Pakistan. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Rohingya
        "rhg": {
            "name": "Rohingya",
            "context": "Use Rohingya as spoken by the Rohingya people. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Romanian
        "ro_RO": {
            "name": "Romanian",
            "context": "Use standard Romanian as spoken in Romania. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=3; plural=(n==1 ? 0 : (n==0 || (n%100 > 0 && n%100 < 20)) ? 1 : 2);"
        },
        # Russian
        "ru_RU": {
            "name": "Russian",
            "context": "Use standard Russian as spoken in Russia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);"
        },
        # Sakha (Yakut)
        "sah": {
            "name": "Sakha",
            "context": "Use Sakha (Yakut) as spoken in the Sakha Republic, Russia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Sindhi
        "snd": {
            "name": "Sindhi",
            "context": "Use standard Sindhi as spoken in Pakistan and India. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Sinhala
        "si_LK": {
            "name": "Sinhala",
            "context": "Use Sinhala as spoken in Sri Lanka. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Slovak
        "sk_SK": {
            "name": "Slovak",
            "context": "Use standard Slovak as spoken in Slovakia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=3; plural=(n==1 ? 0 : (n>=2 && n<=4) ? 1 : 2);"
        },
        # Saraiki
        "skr": {
            "name": "Saraiki",
            "context": "Use Saraiki as spoken in Pakistan. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Slovenian
        "sl_SI": {
            "name": "Slovenian",
            "context": "Use standard Slovenian as spoken in Slovenia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=4; plural=(n%100==1 ? 0 : n%100==2 ? 1 : n%100==3 || n%100==4 ? 2 : 3);"
        },
        # Albanian
        "sq": {
            "name": "Albanian",
            "context": "Use standard Albanian as spoken in Albania. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Serbian
        "sr_RS": {
            "name": "Serbian",
            "context": "Use standard Serbian as spoken in Serbia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);"
        },
        # Swedish
        "sv_SE": {
            "name": "Swedish",
            "context": "Use standard Swedish as spoken in Sweden. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Swahili
        "sw": {
            "name": "Swahili",
            "context": "Use standard Swahili as spoken in East Africa. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Silesian
        "szl": {
            "name": "Silesian",
            "context": "Use Silesian as spoken in Silesia, Poland. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);"
        },
        # Tamil variants
        "ta_IN": {
            "name": "Tamil (India)",
            "context": "Use standard Tamil as spoken in Tamil Nadu, India. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        "ta_LK": {
            "name": "Tamil (Sri Lanka)",
            "context": "Use Tamil as spoken in Sri Lanka. Use formal register appropriate for business and technical contexts. Adapt to Sri Lankan Tamil expressions where appropriate.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Telugu
        "te": {
            "name": "Telugu",
            "context": "Use standard Telugu as spoken in Andhra Pradesh and Telangana, India. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Thai
        "th": {
            "name": "Thai",
            "context": "Use standard Thai as spoken in Thailand. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Tagalog
        "tl": {
            "name": "Tagalog",
            "context": "Use Tagalog as spoken in the Philippines. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n > 1);"
        },
        # Turkish
        "tr_TR": {
            "name": "Turkish",
            "context": "Use standard Turkish as spoken in Turkey. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n > 1);"
        },
        # Tatar
        "tt_RU": {
            "name": "Tatar",
            "context": "Use Tatar as spoken in Tatarstan, Russia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Tahitian
        "tah": {
            "name": "Tahitian",
            "context": "Use Tahitian as spoken in French Polynesia. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n > 1);"
        },
        # Uyghur
        "ug_CN": {
            "name": "Uyghur",
            "context": "Use Uyghur as spoken in Xinjiang, China. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Ukrainian
        "uk": {
            "name": "Ukrainian",
            "context": "Use standard Ukrainian as spoken in Ukraine. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);"
        },
        # Urdu
        "ur": {
            "name": "Urdu",
            "context": "Use standard Urdu as spoken in Pakistan and India. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=2; plural=(n != 1);"
        },
        # Uzbek
        "uz_UZ": {
            "name": "Uzbek",
            "context": "Use standard Uzbek (Latin script) as spoken in Uzbekistan. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Vietnamese
        "vi": {
            "name": "Vietnamese",
            "context": "Use standard Vietnamese as spoken in Vietnam. Use formal register appropriate for business and technical contexts.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        # Chinese variants
        "zh_TW": {
            "name": "Chinese (Taiwan)",
            "context": "Use Traditional Chinese as used in Taiwan. Use formal register appropriate for business and technical contexts. Follow Taiwan terminology standards.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        "zh_CN": {
            "name": "Chinese (Simplified)",
            "context": "Use Simplified Chinese as used in Mainland China. Use formal register appropriate for business and technical contexts. Follow PRC terminology standards.",
            "plural_forms": "nplurals=1; plural=0;"
        },
        "zh_HK": {
            "name": "Chinese (Hong Kong)",
            "context": "Use Traditional Chinese as used in Hong Kong. Use formal register appropriate for business and technical contexts. Follow Hong Kong terminology and Cantonese influences where appropriate.",
            "plural_forms": "nplurals=1; plural=0;"
        },
    }

    def __init__(self, api_key: str, model: str = 'gemini-3-flash-preview',
                 thinking_level: str = 'MEDIUM', batch_size: int = 100):
        self.api_key = api_key
        self.model = model
        self.thinking_level = thinking_level
        self.batch_size = batch_size
        self.translation_cache = {}
        self.total_input_tokens = 0
        self.total_output_tokens = 0
        self.api_calls = 0
        self.full_pot_context = ""
        self.all_msgids = []

    def load_pot_context(self, pot_file_path: str) -> List[str]:
        """Load POT file and prepare full context for translations."""
        pot = polib.pofile(pot_file_path)
        self.all_msgids = [
            entry.msgid for entry in pot
            if not entry.obsolete and entry.msgid and entry.msgid.strip()
        ]
        context_lines = []
        for i, msgid in enumerate(self.all_msgids):
            display_msgid = msgid[:200] + "..." if len(msgid) > 200 else msgid
            context_lines.append(f"[{i}] {display_msgid}")
        self.full_pot_context = "\n".join(context_lines)
        print(f"  Loaded {len(self.all_msgids)} strings for context")
        return self.all_msgids

    def _build_system_prompt(self, lang_code: str) -> str:
        """Build the system instruction for Gemini API calls."""
        lang_config = self.LANGUAGE_CONFIGS.get(lang_code, {})
        lang_name = lang_config.get("name", lang_code)
        regional_context = lang_config.get("context", "")

        return f"""You are a professional translator specializing in WordPress plugin localization and SEO terminology.
You will translate strings from "AI Image SEO Optimizer" - a WordPress plugin for AI-powered image SEO optimization.

## COMPLETE STRING CATALOG (for terminology reference)
Below are ALL strings in this plugin. Use this to maintain consistent terminology across your translations.
When you see the same term used in multiple strings, translate it the same way every time.

{self.full_pot_context}

## PLUGIN CONTEXT
AI Image SEO Optimizer helps website owners improve search rankings by:
- Generating Alt Text (alternative text for accessibility/SEO)
- Creating SEO-optimized image Descriptions and Captions
- Integrating with AI models (GPT-5 mini, Claude, Gemini)
- Providing Media Library interface for image optimization

## KEY TERMINOLOGY for {lang_name}
- "AI" - Artificial Intelligence. Translate to local equivalent if available, otherwise keep as "AI"
- "SEO" - Search Engine Optimization. Translate to local equivalent if available, otherwise keep as "SEO"
- "Alt Text" / "Alt-Text" - Alternative Text for images (accessibility/SEO attribute). Translate to local equivalent
- "Caption" - Image caption/subtitle displayed below images. Translate to local equivalent
- "Description" - Image description metadata for attachment pages. Translate to local equivalent
- "Keywords" - SEO search terms for image optimization (NOT keyboard keys). Translate to local equivalent
- "Optimize" - Improving for search engines. Translate to local equivalent
- "Media Library" - WordPress media area. Translate to local equivalent
- "Bulk" - Processing multiple items at once. Translate to local equivalent

## REGIONAL CONTEXT
{regional_context}

## TRANSLATION RULES
1. Maintain HTML tags, placeholders (%s, %d, %1$s), and special characters EXACTLY
2. Use consistent terminology - same English term = same translation everywhere
3. Keep professional, technical tone
4. Do NOT translate: "AI Image SEO Optimizer", "GPT-5 mini", "Claude", "Gemini", brand names
5. Preserve \\n line breaks exactly
6. Return ONLY valid JSON - no explanations
7. Use standard WordPress terminology for this language

Return translations as JSON: {{"index": "translated text", ...}}"""

    def _call_gemini_api(self, system_prompt: str, user_prompt: str) -> dict:
        """Make a single Gemini API call and return parsed JSON response."""
        url = f"{GEMINI_API_BASE}/{self.model}:generateContent?key={self.api_key}"

        body = {
            'system_instruction': {
                'parts': [{'text': system_prompt}]
            },
            'contents': [
                {'role': 'user', 'parts': [{'text': user_prompt}]}
            ],
            'generationConfig': {
                'response_mime_type': 'application/json',
                'thinking_config': {
                    'thinking_level': self.thinking_level
                }
            }
        }

        response = requests.post(
            url,
            headers={'Content-Type': 'application/json'},
            json=body,
            timeout=120
        )

        if response.status_code != 200:
            error_msg = response.json().get('error', {}).get('message', response.text[:500])
            raise Exception(f"Gemini API error ({response.status_code}): {error_msg}")

        data = response.json()

        # Track token usage
        usage = data.get('usageMetadata', {})
        self.total_input_tokens += usage.get('promptTokenCount', 0)
        self.total_output_tokens += usage.get('candidatesTokenCount', 0)
        self.api_calls += 1

        # Extract text from response (skip thinking parts)
        candidates = data.get('candidates', [])
        if not candidates:
            raise Exception("Gemini API returned no candidates")

        parts = candidates[0].get('content', {}).get('parts', [])
        text_content = ''
        for part in parts:
            if part.get('thought'):
                continue
            if 'text' in part:
                text_content = part['text']
                break

        if not text_content:
            raise Exception("Gemini API returned empty response")

        # Clean markdown fences if present
        text_content = re.sub(r'^```(?:json)?\s*', '', text_content)
        text_content = re.sub(r'\s*```$', '', text_content)

        return json.loads(text_content)

    def safe_translate_batch(self, batch_indices: List[int], lang_code: str) -> Dict[int, str]:
        """Translate a batch safely - returns ONLY real translations, never English fallbacks."""
        lang_config = self.LANGUAGE_CONFIGS.get(lang_code, {})
        lang_name = lang_config.get("name", lang_code)

        batch_json = {}
        for idx in batch_indices:
            batch_json[str(idx)] = self.all_msgids[idx]

        system_prompt = self._build_system_prompt(lang_code)

        user_prompt = f"""Translate these {len(batch_json)} strings to {lang_name}.
Use the complete string catalog above as reference for terminology consistency.

Strings to translate:
{json.dumps(batch_json, ensure_ascii=False, indent=2)}

Return JSON with same keys mapping to translations."""

        try:
            print(f"    Translating batch of {len(batch_indices)} strings...")

            translations = self._call_gemini_api(system_prompt, user_prompt)

            result = {}
            for idx in batch_indices:
                key = str(idx)
                if key in translations and translations[key]:
                    source = self.all_msgids[idx]
                    translation = translations[key]

                    # Skip brand names — they must never be translated
                    if is_untranslatable(source):
                        continue

                    if translation == source:
                        # Same as source — only accept for short technical terms
                        if len(source) <= 10:
                            result[idx] = translation
                        # else: likely English fallback
                    else:
                        result[idx] = translation

            total_tokens = self.total_input_tokens + self.total_output_tokens
            print(f"    Batch OK ({len(result)}/{len(batch_indices)} translated, tokens: {total_tokens:,})")
            return result

        except Exception as e:
            print(f"    BATCH FAILED: {e}")
            return {}

    def translate_pot_file(self, pot_file_path: str, target_lang_code: str,
                           output_dir: Optional[str] = None,
                           output_prefix: str = 'infy-ai-image-seo-optimizer') -> Tuple[str, str]:
        """Full translation of a .pot file to a target language."""
        print(f"\n{'='*60}")
        print(f"  Full Translation to {target_lang_code}")
        print(f"{'='*60}\n")

        pot = polib.pofile(pot_file_path)
        msgids = self.load_pot_context(pot_file_path)
        total = len(msgids)

        num_batches = (total + self.batch_size - 1) // self.batch_size
        print(f"  Will process in {num_batches} batches of {self.batch_size}\n")

        all_translations = {}
        start_time = time.time()

        for batch_num in range(num_batches):
            start_idx = batch_num * self.batch_size
            end_idx = min(start_idx + self.batch_size, total)
            batch_indices = list(range(start_idx, end_idx))

            print(f"  Batch {batch_num + 1}/{num_batches} (strings {start_idx}-{end_idx-1})")

            batch_translations = self.safe_translate_batch(batch_indices, target_lang_code)
            all_translations.update(batch_translations)

            if batch_num < num_batches - 1:
                time.sleep(1)

        # Create PO file
        po = polib.POFile()
        po.metadata = {
            'Project-Id-Version': pot.metadata.get('Project-Id-Version', 'AI Image SEO Optimizer'),
            'Report-Msgid-Bugs-To': pot.metadata.get('Report-Msgid-Bugs-To', ''),
            'POT-Creation-Date': pot.metadata.get('POT-Creation-Date', ''),
            'PO-Revision-Date': datetime.now().strftime('%Y-%m-%d %H:%M%z'),
            'Last-Translator': 'AI Image SEO Optimizer <noreply@infypress.com>',
            'Language-Team': f'{self.LANGUAGE_CONFIGS.get(target_lang_code, {}).get("name", target_lang_code)}',
            'Language': target_lang_code,
            'MIME-Version': '1.0',
            'Content-Type': 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding': '8bit',
            'Plural-Forms': self.LANGUAGE_CONFIGS.get(target_lang_code, {}).get("plural_forms", "nplurals=2; plural=(n != 1);"),
            'X-Generator': 'InfyPress Gemini Translation System',
        }

        entries_by_msgid = {entry.msgid: entry for entry in pot if not entry.obsolete}

        for idx, msgid in enumerate(msgids):
            original_entry = entries_by_msgid.get(msgid)
            translation = all_translations.get(idx, '')

            # Force brand names to empty msgstr — WordPress will use the English original
            if is_untranslatable(msgid):
                translation = ''

            new_entry = polib.POEntry(
                msgid=msgid,
                msgstr=translation,
                occurrences=original_entry.occurrences if original_entry else [],
                comment=original_entry.comment if original_entry else ''
            )

            if original_entry and original_entry.msgid_plural:
                new_entry.msgid_plural = original_entry.msgid_plural
                new_entry.msgstr_plural = {
                    0: translation,
                    1: all_translations.get(idx, '')
                }

            po.append(new_entry)

        if output_dir is None:
            output_dir = os.path.dirname(pot_file_path)

        os.makedirs(output_dir, exist_ok=True)

        po_filename = f"{output_prefix}-{target_lang_code}.po"
        mo_filename = f"{output_prefix}-{target_lang_code}.mo"

        po_file_path_out = os.path.join(output_dir, po_filename)
        mo_file_path = os.path.join(output_dir, mo_filename)

        po.save(po_file_path_out)
        po.save_as_mofile(mo_file_path)

        elapsed = time.time() - start_time
        translated_count = len(all_translations)

        print(f"\n  Translation Complete!")
        print(f"  PO file: {po_file_path_out}")
        print(f"  MO file: {mo_file_path}")
        print(f"  Strings translated: {translated_count}/{total}")
        print(f"  API calls: {self.api_calls}")
        print(f"  Input tokens: {self.total_input_tokens:,}")
        print(f"  Output tokens: {self.total_output_tokens:,}")
        print(f"  Time: {elapsed:.1f}s")

        return po_file_path_out, mo_file_path


# ---------------------------------------------------------------------------
# TranslationManager
# ---------------------------------------------------------------------------

class TranslationManager:
    """Orchestrates the full translation workflow: POT -> update PO -> translate -> compile."""

    def __init__(self, api_key: str = None, model: str = 'gemini-3-flash-preview',
                 thinking_level: str = 'MEDIUM', batch_size: int = 30,
                 dry_run: bool = False):
        self.api_key = api_key
        self.model = model
        self.thinking_level = thinking_level
        self.batch_size = batch_size
        self.dry_run = dry_run

        # Auto-detect plugin based on script location
        script_dir = Path(__file__).parent
        current = script_dir
        while current.name != 'build' and current != current.parent:
            current = current.parent

        self.plugin_dir = current.parent
        self.plugin_name = self.plugin_dir.name
        self.languages_dir = self.plugin_dir / 'languages'

        if 'pro' in self.plugin_name.lower():
            self.text_domain = 'ai-image-seo-pro'
        else:
            self.text_domain = 'infy-ai-image-seo-optimizer'

    def generate_pot(self) -> Path:
        """Generate fresh POT file from PHP source code."""
        print("\n" + "=" * 60)
        print("  Step: Generate POT File")
        print("=" * 60)

        generator = POTGenerator(str(self.plugin_dir), self.text_domain)
        generator.scan_directory()

        total_strings = len(generator.strings) + len(generator.plural_strings)
        if total_strings == 0:
            print("\n  Warning: No translatable strings found!")
            sys.exit(1)

        pot_path = self.languages_dir / f'{self.text_domain}.pot'
        self.languages_dir.mkdir(parents=True, exist_ok=True)
        generator.generate_pot_file(str(pot_path))

        return pot_path

    def update_po_files(self, pot_path: Path, lang_codes: List[str] = None) -> int:
        """Update existing PO files with new entries from POT. Only processes languages that already have .po files."""
        print("\n" + "=" * 60)
        print("  Step: Update PO Files from POT")
        print("=" * 60)

        pot = polib.pofile(str(pot_path))
        po_files = sorted(self.languages_dir.glob(f'{self.text_domain}-*.po'))

        if lang_codes:
            po_files = [po for po in po_files
                        if any(lang in po.stem for lang in lang_codes)]

        total_added = 0

        for po_path_file in po_files:
            po = polib.pofile(str(po_path_file))
            existing_msgids = {entry.msgid for entry in po if entry.msgid}

            added = 0
            for pot_entry in pot:
                if not pot_entry.msgid or pot_entry.msgid in existing_msgids:
                    continue

                new_entry = polib.POEntry(
                    msgid=pot_entry.msgid,
                    msgstr="",
                    occurrences=pot_entry.occurrences,
                    comment=pot_entry.comment
                )
                if pot_entry.msgid_plural:
                    new_entry.msgid_plural = pot_entry.msgid_plural
                    new_entry.msgstr_plural = {0: "", 1: ""}

                po.append(new_entry)
                added += 1

            if added > 0:
                po.save(str(po_path_file))
                lang = po_path_file.stem.replace(f'{self.text_domain}-', '')
                print(f"  {lang}: +{added} new strings")
                total_added += added

        print(f"  Total new strings added: {total_added}")
        return total_added

    def fix_fallbacks(self, lang_codes: List[str] = None) -> int:
        """Clear English fallbacks (entries where msgstr == msgid that aren't brand names)."""
        print("\n" + "=" * 60)
        print("  Step: Clear English Fallbacks")
        print("=" * 60)

        po_files = sorted(self.languages_dir.glob(f'{self.text_domain}-*.po'))

        if lang_codes:
            po_files = [po for po in po_files
                        if any(lang in po.stem for lang in lang_codes)]

        total_cleared = 0

        for po_path in po_files:
            lang = po_path.stem.replace(f'{self.text_domain}-', '')
            if lang.startswith('en_'):
                continue

            po = polib.pofile(str(po_path))
            cleared = 0

            for entry in po:
                if entry.msgstr and is_english_fallback(entry.msgid, entry.msgstr, lang):
                    entry.msgstr = ""
                    cleared += 1

            if cleared > 0:
                po.save(str(po_path))
                print(f"  {lang}: cleared {cleared} English fallbacks")
                total_cleared += cleared

        print(f"  Total cleared: {total_cleared}")
        return total_cleared

    def translate_untranslated(self, lang_codes: List[str] = None) -> Dict:
        """Translate only untranslated strings using safe_translate_batch."""
        print("\n" + "=" * 60)
        print("  Step: Translate Untranslated Strings")
        print("=" * 60)

        if self.dry_run:
            return self._dry_run_report(lang_codes)

        po_files = sorted(self.languages_dir.glob(f'{self.text_domain}-*.po'))

        if lang_codes:
            po_files = [po for po in po_files
                        if any(lang in po.stem for lang in lang_codes)]

        total_stats = {
            'files': 0,
            'strings': 0,
            'remaining': 0,
            'api_calls': 0,
            'input_tokens': 0,
            'output_tokens': 0,
        }

        for po_path in po_files:
            lang_code = po_path.stem.replace(f'{self.text_domain}-', '')
            po = polib.pofile(str(po_path))
            untranslated = [e for e in po if not e.translated() and e.msgid]

            if not untranslated:
                continue

            print(f"\n  {lang_code}: {len(untranslated)} untranslated strings...")

            translator = GeminiTranslator(
                self.api_key, model=self.model,
                thinking_level=self.thinking_level,
                batch_size=self.batch_size
            )
            all_msgids = [entry.msgid for entry in po if entry.msgid]
            translator.all_msgids = all_msgids

            context_lines = []
            for i, msgid in enumerate(all_msgids):
                preview = msgid[:100] + "..." if len(msgid) > 100 else msgid
                context_lines.append(f"[{i}] {preview}")
            translator.full_pot_context = "\n".join(context_lines)

            # Map untranslated to indices
            untranslated_indices = []
            msgid_to_entry = {}
            for entry in untranslated:
                if entry.msgid in all_msgids:
                    idx = all_msgids.index(entry.msgid)
                    untranslated_indices.append(idx)
                    msgid_to_entry[idx] = entry

            # Translate in batches
            translated_count = 0
            for i in range(0, len(untranslated_indices), self.batch_size):
                batch_indices = untranslated_indices[i:i + self.batch_size]
                translations = translator.safe_translate_batch(batch_indices, lang_code)

                for idx, translation in translations.items():
                    if idx in msgid_to_entry and translation:
                        # Skip brand names — they must stay untranslated
                        if is_untranslatable(msgid_to_entry[idx].msgid):
                            continue
                        msgid_to_entry[idx].msgstr = translation
                        translated_count += 1

            # Save
            po.save(str(po_path))
            mo_path = po_path.with_suffix('.mo')
            po.save_as_mofile(str(mo_path))

            remaining = len(untranslated) - translated_count
            print(f"    Done: {translated_count} translated, {remaining} remaining")

            total_stats['files'] += 1
            total_stats['strings'] += translated_count
            total_stats['remaining'] += remaining
            total_stats['api_calls'] += translator.api_calls
            total_stats['input_tokens'] += translator.total_input_tokens
            total_stats['output_tokens'] += translator.total_output_tokens

        return total_stats

    def translate_full(self, lang_codes: List[str]) -> Dict:
        """Full retranslation of specified languages (all strings)."""
        print("\n" + "=" * 60)
        print("  Step: Full Retranslation")
        print("=" * 60)

        pot_path = self.languages_dir / f'{self.text_domain}.pot'
        if not pot_path.exists():
            print(f"  Error: POT file not found: {pot_path}")
            sys.exit(1)

        total_stats = {
            'files': 0,
            'strings': 0,
            'api_calls': 0,
            'input_tokens': 0,
            'output_tokens': 0,
        }

        for lang_code in lang_codes:
            if lang_code not in GeminiTranslator.LANGUAGE_CONFIGS:
                print(f"  Warning: Unknown language {lang_code}, skipping")
                continue

            if self.dry_run:
                pot = polib.pofile(str(pot_path))
                total = len([e for e in pot if e.msgid])
                print(f"  [DRY RUN] {lang_code}: Would translate all {total} strings")
                continue

            translator = GeminiTranslator(
                self.api_key, model=self.model,
                thinking_level=self.thinking_level,
                batch_size=self.batch_size
            )
            po_file, mo_file = translator.translate_pot_file(
                str(pot_path), lang_code,
                str(self.languages_dir),
                output_prefix=self.text_domain
            )

            total_stats['files'] += 1
            total_stats['api_calls'] += translator.api_calls
            total_stats['input_tokens'] += translator.total_input_tokens
            total_stats['output_tokens'] += translator.total_output_tokens

        return total_stats

    def get_status(self, lang_codes: List[str] = None):
        """Show translation stats for all languages."""
        print("\n" + "=" * 60)
        print("  Translation Status")
        print("=" * 60)

        pot_path = self.languages_dir / f'{self.text_domain}.pot'
        pot_total = 0
        if pot_path.exists():
            pot = polib.pofile(str(pot_path))
            pot_total = len([e for e in pot if e.msgid])
            print(f"  POT file: {pot_total} strings")

        po_files = sorted(self.languages_dir.glob(f'{self.text_domain}-*.po'))

        if lang_codes:
            po_files = [po for po in po_files
                        if any(lang in po.stem for lang in lang_codes)]

        print(f"  PO files: {len(po_files)}\n")
        print(f"  {'Language':<20} {'Total':>6} {'Done':>6} {'Todo':>6} {'%':>6}")
        print(f"  {'-'*20} {'-'*6} {'-'*6} {'-'*6} {'-'*6}")

        total_translated = 0
        total_untranslated = 0
        total_entries = 0

        for po_path in po_files:
            lang = po_path.stem.replace(f'{self.text_domain}-', '')
            po = polib.pofile(str(po_path))

            total = len([e for e in po if e.msgid])
            translated = len([e for e in po if e.translated()])
            untranslated = total - translated
            pct = (translated / total * 100) if total > 0 else 0

            total_entries += total
            total_translated += translated
            total_untranslated += untranslated

            marker = "" if pct == 100 else " <--" if untranslated > 0 else ""
            print(f"  {lang:<20} {total:>6} {translated:>6} {untranslated:>6} {pct:>5.1f}%{marker}")

        if po_files:
            overall_pct = (total_translated / total_entries * 100) if total_entries > 0 else 0
            print(f"  {'-'*20} {'-'*6} {'-'*6} {'-'*6} {'-'*6}")
            print(f"  {'TOTAL':<20} {total_entries:>6} {total_translated:>6} {total_untranslated:>6} {overall_pct:>5.1f}%")

    def _dry_run_report(self, lang_codes: List[str] = None) -> Dict:
        """Preview what would be translated without making API calls."""
        po_files = sorted(self.languages_dir.glob(f'{self.text_domain}-*.po'))

        if lang_codes:
            po_files = [po for po in po_files
                        if any(lang in po.stem for lang in lang_codes)]

        total_untranslated = 0

        for po_path in po_files:
            lang = po_path.stem.replace(f'{self.text_domain}-', '')
            po = polib.pofile(str(po_path))
            untranslated = [e for e in po if not e.translated() and e.msgid]

            if untranslated:
                print(f"  [DRY RUN] {lang}: {len(untranslated)} strings to translate")
                total_untranslated += len(untranslated)

        print(f"\n  Total strings that would be translated: {total_untranslated}")
        return {'files': 0, 'strings': 0, 'remaining': total_untranslated,
                'api_calls': 0, 'input_tokens': 0, 'output_tokens': 0}

    def show_untranslated(self, lang_codes: List[str] = None):
        """List actual untranslated strings per language."""
        print("\n" + "=" * 60)
        print("  Untranslated Strings")
        print("=" * 60)

        po_files = sorted(self.languages_dir.glob(f'{self.text_domain}-*.po'))

        if lang_codes:
            po_files = [po for po in po_files
                        if any(lang in po.stem for lang in lang_codes)]

        total_untranslated = 0

        for po_path in po_files:
            lang = po_path.stem.replace(f'{self.text_domain}-', '')
            po = polib.pofile(str(po_path))
            untranslated = [e for e in po if not e.translated() and e.msgid]

            if not untranslated:
                continue

            total_untranslated += len(untranslated)
            print(f"\n  {lang} ({len(untranslated)} untranslated):")
            for i, entry in enumerate(untranslated, 1):
                preview = entry.msgid if len(entry.msgid) <= 80 else entry.msgid[:77] + "..."
                print(f"    {i:>3}. \"{preview}\"")

        if total_untranslated == 0:
            print("\n  All strings are translated!")
        else:
            print(f"\n  Total untranslated: {total_untranslated}")

    def run(self, skip_pot: bool = False, fix: bool = False,
            full_langs: List[str] = None, generate_pot_only: bool = False,
            status_only: bool = False, show_untranslated: bool = False,
            lang_codes: List[str] = None):
        """Main orchestration: pot -> update -> translate -> compile."""

        print("\n" + "=" * 60)
        print("  InfyPress Gemini Translation System")
        print("=" * 60)
        print(f"  Plugin: {self.text_domain}")
        print(f"  Plugin dir: {self.plugin_dir}")
        print(f"  Languages dir: {self.languages_dir}")
        print(f"  Model: {self.model}")
        print(f"  Thinking: {self.thinking_level}")
        print(f"  Batch size: {self.batch_size}")
        if self.dry_run:
            print(f"  Mode: DRY RUN")
        if lang_codes:
            print(f"  Languages: {', '.join(lang_codes)}")
        print("=" * 60)

        start_time = time.time()

        if status_only:
            self.get_status(lang_codes)
            return

        if show_untranslated:
            self.show_untranslated(lang_codes)
            return

        if full_langs:
            if not skip_pot:
                self.generate_pot()
            self.translate_full(full_langs)
            elapsed = time.time() - start_time
            print(f"\n  Total time: {elapsed:.1f}s")
            return

        if generate_pot_only:
            self.generate_pot()
            return

        # Standard workflow
        pot_path = self.languages_dir / f'{self.text_domain}.pot'
        if not skip_pot:
            pot_path = self.generate_pot()
        else:
            print("\n  [Skipping POT regeneration]")
            if not pot_path.exists():
                print(f"  Error: POT file not found: {pot_path}")
                sys.exit(1)

        self.update_po_files(pot_path, lang_codes)

        if fix:
            self.fix_fallbacks(lang_codes)

        stats = self.translate_untranslated(lang_codes)

        # Summary
        elapsed = time.time() - start_time
        print("\n" + "=" * 60)
        print("  Summary")
        print("=" * 60)
        print(f"  Files processed: {stats['files']}")
        print(f"  Strings translated: {stats['strings']}")
        if stats.get('remaining', 0) > 0:
            print(f"  Strings remaining: {stats['remaining']}")
        if not self.dry_run and stats['api_calls'] > 0:
            print(f"  API calls: {stats['api_calls']}")
            print(f"  Input tokens: {stats['input_tokens']:,}")
            print(f"  Output tokens: {stats['output_tokens']:,}")
            # Gemini 3 Flash: $0.50/M input, $3.00/M output
            input_cost = (stats['input_tokens'] / 1_000_000) * 0.50
            output_cost = (stats['output_tokens'] / 1_000_000) * 3.00
            print(f"  Estimated cost: ${input_cost + output_cost:.4f}")
        print(f"  Total time: {elapsed:.1f}s")
        print("=" * 60)
        print("  Done!")
        print("=" * 60 + "\n")


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(
        description='InfyPress Gemini Translation System',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python3 translate.py                          # Full workflow
  python3 translate.py --languages de_DE es_ES  # Specific languages
  python3 translate.py --skip-pot               # Skip POT regeneration
  python3 translate.py --dry-run                # Preview without API calls
  python3 translate.py --fix                    # Clear English fallbacks first
  python3 translate.py --full es_ES             # Full retranslation of a language
  python3 translate.py --generate-pot           # Only generate POT
  python3 translate.py --status                 # Show translation stats
  python3 translate.py --show-untranslated      # List untranslated strings
  python3 translate.py --thinking LOW           # Use LOW thinking level
  python3 translate.py --batch-size 50          # Custom batch size
        """
    )

    parser.add_argument('--languages', nargs='+',
                        help='Specific language codes to translate')
    parser.add_argument('--skip-pot', action='store_true',
                        help='Skip POT file regeneration')
    parser.add_argument('--dry-run', action='store_true',
                        help='Preview changes without making API calls')
    parser.add_argument('--fix', action='store_true',
                        help='Clear English fallbacks before translating')
    parser.add_argument('--full', nargs='+', metavar='LANG',
                        help='Full retranslation of specified language(s)')
    parser.add_argument('--generate-pot', action='store_true',
                        help='Only generate POT file, no translation')
    parser.add_argument('--status', action='store_true',
                        help='Show translation stats for all languages')
    parser.add_argument('--show-untranslated', action='store_true',
                        help='List actual untranslated strings per language')
    parser.add_argument('--model', default='gemini-3-flash-preview',
                        choices=['gemini-3-flash-preview', 'gemini-3-pro-preview'],
                        help='Gemini model to use (default: gemini-3-flash-preview)')
    parser.add_argument('--thinking', default='MEDIUM',
                        choices=['LOW', 'MEDIUM', 'HIGH'],
                        help='Thinking level for Gemini (default: MEDIUM)')
    parser.add_argument('--batch-size', type=int, default=30,
                        help='Number of strings per API call (default: 30)')
    parser.add_argument('--api-key',
                        help='Google API key (or set GOOGLE_API_KEY env variable)')

    args = parser.parse_args()

    # Get API key
    api_key = args.api_key or os.getenv('GOOGLE_API_KEY')
    needs_api = not (args.dry_run or args.generate_pot or args.status or args.show_untranslated)

    if not api_key and needs_api:
        print("Error: GOOGLE_API_KEY not set. Use --api-key or set environment variable.")
        print("       Or use --dry-run, --generate-pot, or --status for offline operations.")
        sys.exit(1)

    # Validate --full language codes
    if args.full:
        invalid = [lang for lang in args.full if lang not in GeminiTranslator.LANGUAGE_CONFIGS]
        if invalid:
            print(f"Error: Invalid language code(s): {', '.join(invalid)}")
            print(f"Available: {', '.join(sorted(GeminiTranslator.LANGUAGE_CONFIGS.keys()))}")
            sys.exit(1)

    # Validate --languages codes
    if args.languages:
        invalid = [lang for lang in args.languages if lang not in GeminiTranslator.LANGUAGE_CONFIGS]
        if invalid:
            print(f"Error: Invalid language code(s): {', '.join(invalid)}")
            print(f"Available: {', '.join(sorted(GeminiTranslator.LANGUAGE_CONFIGS.keys()))}")
            sys.exit(1)

    manager = TranslationManager(
        api_key=api_key,
        model=args.model,
        thinking_level=args.thinking,
        batch_size=args.batch_size,
        dry_run=args.dry_run,
    )

    manager.run(
        skip_pot=args.skip_pot,
        fix=args.fix,
        full_langs=args.full,
        generate_pot_only=args.generate_pot,
        status_only=args.status,
        show_untranslated=args.show_untranslated,
        lang_codes=args.languages,
    )


if __name__ == '__main__':
    main()
