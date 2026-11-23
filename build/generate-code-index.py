#!/usr/bin/env python3
"""
Infy - AI Image SEO Optimizer — Code Index Generator

Scans both free and pro plugins via regex, extracting classes, methods, hooks,
AJAX endpoints, options, transients, meta keys, constants, database tables,
JS modules, and CSS selectors. Generates structured markdown index files with
exact file:line references for AI agent navigation.

Usage:
    python3 build/generate-code-index.py              # Index both plugins
    python3 build/generate-code-index.py --free        # Free plugin only
    python3 build/generate-code-index.py --pro         # Pro plugin only
    python3 build/generate-code-index.py --stats       # Print extraction counts only
    python3 build/generate-code-index.py --claude-md   # Also generate CLAUDE.md at plugin roots
    python3 build/generate-code-index.py --dry-run     # Preview without writing
    python3 build/generate-code-index.py --help        # Show help

Author: InfyPress
Version: 1.0.0
"""

import os
import re
import argparse
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path
from typing import List, Optional, Dict, Tuple, Set

# =============================================================================
# Configuration
# =============================================================================

SCRIPT_DIR = Path(__file__).parent.resolve()
PLUGIN_ROOT = SCRIPT_DIR.parent  # Free plugin root

FREE_PLUGIN_DIR = PLUGIN_ROOT
PRO_PLUGIN_DIR = PLUGIN_ROOT.parent / 'infy-ai-image-seo-optimizer-pro'

FREE_PLUGIN_SLUG = 'infy-ai-image-seo-optimizer'
PRO_PLUGIN_SLUG = 'infy-ai-image-seo-optimizer-pro'

OUTPUT_SUBDIR = 'docs/claude/generated'

SKIP_DIRS = {'vendor', 'node_modules', 'build', 'dist', '.git', '.claude', 'backups', 'languages'}
PHP_EXTENSIONS = {'.php'}
JS_EXTENSIONS = {'.js'}
CSS_EXTENSIONS = {'.css'}

PREFIX = 'infyp_'
CLASS_PREFIX = 'INFYP_'
CSS_PREFIX = '.infyp-'
CSS_VAR_PREFIX = '--infyp-'

NAMESPACE_ROOT = 'IPAIS'

# =============================================================================
# Terminal Colors (matches build.py)
# =============================================================================

class Colors:
    RED = '\033[0;31m'
    GREEN = '\033[0;32m'
    YELLOW = '\033[1;33m'
    BLUE = '\033[0;34m'
    CYAN = '\033[0;36m'
    MAGENTA = '\033[0;35m'
    NC = '\033[0m'
    BOLD = '\033[1m'


def print_color(color: str, message: str) -> None:
    print(f"{color}{message}{Colors.NC}")


def print_header(title: str) -> None:
    print()
    print_color(Colors.CYAN, "=" * 62)
    print_color(Colors.CYAN, f"  {title}")
    print_color(Colors.CYAN, "=" * 62)


def print_success(message: str) -> None:
    print_color(Colors.GREEN, f"  ✓ {message}")


def print_info(message: str) -> None:
    print_color(Colors.BLUE, f"  → {message}")


def print_warn(message: str) -> None:
    print_color(Colors.YELLOW, f"  ⚠ {message}")


# =============================================================================
# Data Classes
# =============================================================================

@dataclass
class MethodInfo:
    name: str
    line: int
    visibility: str  # 'public', 'private', 'protected'
    is_static: bool
    params: str


@dataclass
class ClassInfo:
    name: str
    file: str  # relative path
    line: int
    namespace: str
    extends: str
    implements: str
    methods: List[MethodInfo] = field(default_factory=list)


@dataclass
class ConstantInfo:
    name: str
    value: str
    file: str
    line: int


@dataclass
class HookDefinition:
    """An apply_filters() or do_action() call in the source."""
    hook_name: str
    hook_type: str  # 'filter' or 'action'
    file: str
    line: int
    params: str


@dataclass
class HookRegistration:
    """An add_filter() or add_action() call."""
    hook_name: str
    hook_type: str  # 'filter' or 'action'
    callback: str
    priority: str
    file: str
    line: int


@dataclass
class AjaxEndpoint:
    action: str
    handler_method: str
    handler_class: str
    file: str
    line: int
    registration_type: str  # 'array' or 'direct'


@dataclass
class OptionReference:
    key: str
    operation: str  # 'get_option', 'update_option', 'delete_option', etc.
    file: str
    line: int


@dataclass
class MetaReference:
    key: str
    meta_type: str  # 'post_meta', 'user_meta', 'transient'
    operation: str
    file: str
    line: int


@dataclass
class DatabaseTable:
    table_name: str
    file: str
    line: int


@dataclass
class JSAjaxAction:
    action: str
    file: str
    line: int


@dataclass
class JSModuleInfo:
    file: str
    imports: List[str] = field(default_factory=list)
    exports: List[str] = field(default_factory=list)
    ajax_actions: List[JSAjaxAction] = field(default_factory=list)


@dataclass
class CSSSelector:
    selector: str
    file: str
    line: int


@dataclass
class CSSCustomProp:
    prop: str
    value: str
    file: str
    line: int


# =============================================================================
# File Walker
# =============================================================================

def walk_files(plugin_dir: Path, extensions: Set[str]) -> List[Path]:
    """Walk plugin directory, yield files matching extensions, skip ignored dirs."""
    results = []
    for root, dirs, files in os.walk(plugin_dir):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for f in sorted(files):
            if Path(f).suffix in extensions:
                results.append(Path(root) / f)
    return results


def relative_path(filepath: Path, plugin_dir: Path) -> str:
    """Get path relative to plugin root."""
    return str(filepath.relative_to(plugin_dir))


def line_number_at(content: str, pos: int) -> int:
    """Calculate 1-based line number at character position."""
    return content[:pos].count('\n') + 1


# =============================================================================
# PHP File Parser
# =============================================================================

class PHPFileParser:
    """Parse a single PHP file and extract all code entities."""

    # Pre-compiled patterns
    RE_CLASS = re.compile(
        r'^(?:\s*)(?:abstract\s+|final\s+)?class\s+(INFYP_\w+)'
        r'(?:\s+extends\s+([\w\\]+))?'
        r'(?:\s+implements\s+([\w\\,\s]+))?',
        re.MULTILINE
    )
    RE_NAMESPACE = re.compile(r'^\s*namespace\s+([\w\\]+)\s*;', re.MULTILINE)
    RE_METHOD = re.compile(
        r'^\s+(public|private|protected)\s+(static\s+)?function\s+(\w+)\s*\(([^)]*)\)',
        re.MULTILINE
    )
    RE_CONSTANT = re.compile(
        r"""define\s*\(\s*['"](\w+)['"]\s*,\s*(.+?)\s*\)""",
        re.MULTILINE
    )
    RE_APPLY_FILTERS = re.compile(
        r"""apply_filters\s*\(\s*['"]([^'"]+)['"]""",
        re.DOTALL
    )
    RE_DO_ACTION = re.compile(
        r"""do_action\s*\(\s*['"]([^'"]+)['"]""",
        re.DOTALL
    )
    RE_ADD_FILTER = re.compile(
        r"""add_filter\s*\(\s*['"]([^'"]+)['"]\s*,\s*(.+?)(?:\s*,\s*(\d+))?\s*\)""",
        re.DOTALL
    )
    RE_ADD_ACTION = re.compile(
        r"""add_action\s*\(\s*['"]([^'"]+)['"]\s*,\s*(.+?)(?:\s*,\s*(\d+))?\s*\)""",
        re.DOTALL
    )
    # Dynamic hook names: 'infyp_' . $var or "infyp_{$var}"
    RE_APPLY_FILTERS_DYNAMIC = re.compile(
        r"""apply_filters\s*\(\s*['"]([^'"]*infyp_[^'"]*)['"]\s*\.\s*\$(\w+)""",
        re.DOTALL
    )
    RE_DO_ACTION_DYNAMIC = re.compile(
        r"""do_action\s*\(\s*['"]([^'"]*infyp_[^'"]*)['"]\s*\.\s*\$(\w+)""",
        re.DOTALL
    )
    RE_GET_OPTION = re.compile(
        r"""get_option\s*\(\s*['"]([^'"]+)['"]""",
    )
    RE_UPDATE_OPTION = re.compile(
        r"""update_option\s*\(\s*['"]([^'"]+)['"]""",
    )
    RE_DELETE_OPTION = re.compile(
        r"""delete_option\s*\(\s*['"]([^'"]+)['"]""",
    )
    RE_GET_TRANSIENT = re.compile(
        r"""get_transient\s*\(\s*['"]([^'"]+)['"]""",
    )
    RE_SET_TRANSIENT = re.compile(
        r"""set_transient\s*\(\s*['"]([^'"]+)['"]""",
    )
    RE_DELETE_TRANSIENT = re.compile(
        r"""delete_transient\s*\(\s*['"]([^'"]+)['"]""",
    )
    RE_POST_META = re.compile(
        r"""(get|update|delete|add)_post_meta\s*\(\s*\$\w+\s*,\s*['"]([^'"]+)['"]""",
    )
    RE_USER_META = re.compile(
        r"""(get|update|delete|add)_user_meta\s*\(\s*\$\w+\s*,\s*['"]([^'"]+)['"]""",
    )
    RE_DB_TABLE = re.compile(
        r"""\$wpdb->prefix\s*\.\s*['"](\w+)['"]""",
    )
    RE_AJAX_ARRAY_HANDLER = re.compile(
        r"""['"](\w+)['"]\s*=>\s*['"](\w+)['"]""",
    )
    RE_WP_AJAX_DIRECT = re.compile(
        r"""add_action\s*\(\s*['"]wp_ajax_(\w+)['"]""",
    )

    def __init__(self, filepath: Path, plugin_dir: Path):
        self.filepath = filepath
        self.plugin_dir = plugin_dir
        self.rel_path = relative_path(filepath, plugin_dir)
        self.content = filepath.read_text(encoding='utf-8', errors='replace')

    def parse_namespace(self) -> str:
        m = self.RE_NAMESPACE.search(self.content)
        return m.group(1) if m else ''

    def parse_classes(self) -> List[ClassInfo]:
        classes = []
        namespace = self.parse_namespace()

        for m in self.RE_CLASS.finditer(self.content):
            line = line_number_at(self.content, m.start())
            cls = ClassInfo(
                name=m.group(1),
                file=self.rel_path,
                line=line,
                namespace=namespace,
                extends=m.group(2) or '',
                implements=(m.group(3) or '').strip(),
                methods=[]
            )

            # Find methods within this class scope
            # Look from the class declaration to the next class or end of file
            class_start = m.start()
            next_class = self.RE_CLASS.search(self.content, m.end())
            class_end = next_class.start() if next_class else len(self.content)
            class_body = self.content[class_start:class_end]

            for mm in self.RE_METHOD.finditer(class_body):
                method_line = line_number_at(self.content, class_start + mm.start())
                cls.methods.append(MethodInfo(
                    name=mm.group(3),
                    line=method_line,
                    visibility=mm.group(1),
                    is_static=mm.group(2) is not None,
                    params=mm.group(4).strip()
                ))

            classes.append(cls)
        return classes

    def parse_constants(self) -> List[ConstantInfo]:
        constants = []
        for m in self.RE_CONSTANT.finditer(self.content):
            name = m.group(1)
            if name.startswith('INFYP_') or name.startswith('IPAIS'):
                constants.append(ConstantInfo(
                    name=name,
                    value=m.group(2).strip().rstrip(')').strip(),
                    file=self.rel_path,
                    line=line_number_at(self.content, m.start())
                ))
        return constants

    def parse_hook_definitions(self) -> List[HookDefinition]:
        hooks = []
        # apply_filters
        for m in self.RE_APPLY_FILTERS.finditer(self.content):
            hook_name = m.group(1)
            if PREFIX in hook_name:
                # Extract parameters after the hook name
                params = self._extract_hook_params(m.end())
                hooks.append(HookDefinition(
                    hook_name=hook_name,
                    hook_type='filter',
                    file=self.rel_path,
                    line=line_number_at(self.content, m.start()),
                    params=params
                ))

        # do_action
        for m in self.RE_DO_ACTION.finditer(self.content):
            hook_name = m.group(1)
            if PREFIX in hook_name:
                params = self._extract_hook_params(m.end())
                hooks.append(HookDefinition(
                    hook_name=hook_name,
                    hook_type='action',
                    file=self.rel_path,
                    line=line_number_at(self.content, m.start()),
                    params=params
                ))

        # Dynamic hook names
        for m in self.RE_APPLY_FILTERS_DYNAMIC.finditer(self.content):
            hook_name = f"{m.group(1)}{{${m.group(2)}}}"
            params = self._extract_hook_params(m.end())
            hooks.append(HookDefinition(
                hook_name=hook_name,
                hook_type='filter',
                file=self.rel_path,
                line=line_number_at(self.content, m.start()),
                params=params
            ))

        for m in self.RE_DO_ACTION_DYNAMIC.finditer(self.content):
            hook_name = f"{m.group(1)}{{${m.group(2)}}}"
            params = self._extract_hook_params(m.end())
            hooks.append(HookDefinition(
                hook_name=hook_name,
                hook_type='action',
                file=self.rel_path,
                line=line_number_at(self.content, m.start()),
                params=params
            ))

        return hooks

    def _extract_hook_params(self, after_name_pos: int) -> str:
        """Extract parameter names from after the hook name in apply_filters/do_action."""
        # Find the closing paren, collecting params
        depth = 1
        i = after_name_pos
        params_start = None
        while i < len(self.content) and depth > 0:
            c = self.content[i]
            if c == '(':
                depth += 1
            elif c == ')':
                depth -= 1
                if depth == 0:
                    break
            elif c == ',' and params_start is None:
                params_start = i + 1
            i += 1

        if params_start is None:
            return ''

        raw = self.content[params_start:i].strip()
        # Clean up multiline and simplify
        raw = re.sub(r'\s+', ' ', raw)
        # Extract variable names
        param_vars = re.findall(r'\$[\w]+', raw)
        return ', '.join(param_vars)

    def parse_hook_registrations(self) -> List[HookRegistration]:
        regs = []

        for m in self.RE_ADD_FILTER.finditer(self.content):
            hook_name = m.group(1)
            if PREFIX in hook_name or hook_name.startswith('wp_ajax_'):
                callback = m.group(2).strip().rstrip(',')
                priority = m.group(3) or '10'
                regs.append(HookRegistration(
                    hook_name=hook_name,
                    hook_type='filter',
                    callback=self._clean_callback(callback),
                    priority=priority,
                    file=self.rel_path,
                    line=line_number_at(self.content, m.start())
                ))

        for m in self.RE_ADD_ACTION.finditer(self.content):
            hook_name = m.group(1)
            if PREFIX in hook_name or hook_name.startswith('wp_ajax_'):
                callback = m.group(2).strip().rstrip(',')
                priority = m.group(3) or '10'
                regs.append(HookRegistration(
                    hook_name=hook_name,
                    hook_type='action',
                    callback=self._clean_callback(callback),
                    priority=priority,
                    file=self.rel_path,
                    line=line_number_at(self.content, m.start())
                ))

        return regs

    def _clean_callback(self, raw: str) -> str:
        """Clean up callback representation."""
        raw = raw.strip()
        # Array callback: [$this, 'method'] or [ClassName::class, 'method']
        arr_match = re.match(r"""\[\s*(?:\$this|(\w+)(?:::class)?)\s*,\s*['"](\w+)['"]\s*\]""", raw)
        if arr_match:
            cls_name = arr_match.group(1) or '{self}'
            method = arr_match.group(2)
            return f"{cls_name}::{method}"
        # String callback: 'function_name' or '__return_true'
        str_match = re.match(r"""['"](\w+)['"]""", raw)
        if str_match:
            return str_match.group(1)
        return raw

    def parse_ajax_endpoints(self) -> List[AjaxEndpoint]:
        endpoints = []

        # Detect array-based handler registration pattern
        # Look for $handlers = [...] followed by foreach with wp_ajax_ registration
        for array_match in re.finditer(
            r'\$handlers\s*=\s*\[(.*?)\]\s*;',
            self.content, re.DOTALL
        ):
            # Verify this array is used for AJAX registration by checking
            # the ~200 chars after the array for a foreach with wp_ajax_
            after_array = self.content[array_match.end():array_match.end() + 300]
            if 'wp_ajax_' not in after_array:
                continue

            class_name = self._find_enclosing_class(array_match.start())

            for entry in self.RE_AJAX_ARRAY_HANDLER.finditer(array_match.group(1)):
                action = entry.group(1)
                handler = entry.group(2)
                entry_line = line_number_at(self.content, array_match.start() + entry.start())
                endpoints.append(AjaxEndpoint(
                    action=action,
                    handler_method=handler,
                    handler_class=class_name,
                    file=self.rel_path,
                    line=entry_line,
                    registration_type='array'
                ))

        # Direct wp_ajax_ registrations
        for m in self.RE_WP_AJAX_DIRECT.finditer(self.content):
            action = m.group(1)
            # Skip if already captured via array pattern
            if any(e.action == action for e in endpoints):
                continue
            class_name = self._find_enclosing_class(m.start())
            # Try to extract callback
            callback_match = re.search(
                r""",\s*\[\s*\$this\s*,\s*['"](\w+)['"]\s*\]""",
                self.content[m.start():m.start() + 200]
            )
            handler = callback_match.group(1) if callback_match else '?'
            endpoints.append(AjaxEndpoint(
                action=action,
                handler_method=handler,
                handler_class=class_name,
                file=self.rel_path,
                line=line_number_at(self.content, m.start()),
                registration_type='direct'
            ))

        return endpoints

    def _find_enclosing_class(self, pos: int) -> str:
        """Find the class name enclosing a position."""
        best = ''
        for m in self.RE_CLASS.finditer(self.content):
            if m.start() < pos:
                best = m.group(1)
            else:
                break
        return best

    def parse_options(self) -> List[OptionReference]:
        refs = []
        patterns = [
            (self.RE_GET_OPTION, 'get_option'),
            (self.RE_UPDATE_OPTION, 'update_option'),
            (self.RE_DELETE_OPTION, 'delete_option'),
        ]
        for regex, op in patterns:
            for m in regex.finditer(self.content):
                key = m.group(1)
                if PREFIX in key or key.startswith('infyp'):
                    refs.append(OptionReference(
                        key=key,
                        operation=op,
                        file=self.rel_path,
                        line=line_number_at(self.content, m.start())
                    ))
        return refs

    def parse_transients(self) -> List[MetaReference]:
        refs = []
        patterns = [
            (self.RE_GET_TRANSIENT, 'get_transient'),
            (self.RE_SET_TRANSIENT, 'set_transient'),
            (self.RE_DELETE_TRANSIENT, 'delete_transient'),
        ]
        for regex, op in patterns:
            for m in regex.finditer(self.content):
                key = m.group(1)
                if PREFIX in key or key.startswith('infyp'):
                    refs.append(MetaReference(
                        key=key,
                        meta_type='transient',
                        operation=op,
                        file=self.rel_path,
                        line=line_number_at(self.content, m.start())
                    ))
        return refs

    def parse_post_meta(self) -> List[MetaReference]:
        refs = []
        for m in self.RE_POST_META.finditer(self.content):
            key = m.group(2)
            if PREFIX in key or key.startswith('_infyp') or key.startswith('infyp'):
                refs.append(MetaReference(
                    key=key,
                    meta_type='post_meta',
                    operation=f"{m.group(1)}_post_meta",
                    file=self.rel_path,
                    line=line_number_at(self.content, m.start())
                ))
        return refs

    def parse_user_meta(self) -> List[MetaReference]:
        refs = []
        for m in self.RE_USER_META.finditer(self.content):
            key = m.group(2)
            if PREFIX in key or key.startswith('_infyp') or key.startswith('infyp'):
                refs.append(MetaReference(
                    key=key,
                    meta_type='user_meta',
                    operation=f"{m.group(1)}_user_meta",
                    file=self.rel_path,
                    line=line_number_at(self.content, m.start())
                ))
        return refs

    def parse_database_tables(self) -> List[DatabaseTable]:
        tables = []
        seen = set()
        for m in self.RE_DB_TABLE.finditer(self.content):
            table = m.group(1)
            if PREFIX in table or table.startswith('infyp'):
                key = (table, self.rel_path)
                if key not in seen:
                    seen.add(key)
                    tables.append(DatabaseTable(
                        table_name=table,
                        file=self.rel_path,
                        line=line_number_at(self.content, m.start())
                    ))
        return tables


# =============================================================================
# JS File Parser
# =============================================================================

class JSFileParser:
    """Parse a single JS file for AJAX actions, imports, and exports."""

    RE_AJAX_ACTION = re.compile(
        r"""action\s*:\s*['"](\w+)['"]""",
    )
    RE_IMPORT = re.compile(
        r"""import\s+(?:\{[^}]+\}|[\w]+)\s+from\s+['"]([^'"]+)['"]""",
    )
    RE_EXPORT = re.compile(
        r"""export\s+(?:default\s+)?(?:function|class|const|let|var)\s+(\w+)""",
    )

    def __init__(self, filepath: Path, plugin_dir: Path):
        self.filepath = filepath
        self.plugin_dir = plugin_dir
        self.rel_path = relative_path(filepath, plugin_dir)
        self.content = filepath.read_text(encoding='utf-8', errors='replace')

    def parse(self) -> JSModuleInfo:
        info = JSModuleInfo(file=self.rel_path)

        for m in self.RE_AJAX_ACTION.finditer(self.content):
            action = m.group(1)
            if action.startswith(PREFIX):
                info.ajax_actions.append(JSAjaxAction(
                    action=action,
                    file=self.rel_path,
                    line=line_number_at(self.content, m.start())
                ))

        for m in self.RE_IMPORT.finditer(self.content):
            info.imports.append(m.group(1))

        for m in self.RE_EXPORT.finditer(self.content):
            info.exports.append(m.group(1))

        return info


# =============================================================================
# CSS File Parser
# =============================================================================

class CSSFileParser:
    """Parse a single CSS file for .infyp-* selectors and --infyp-* custom props."""

    RE_SELECTOR = re.compile(r'(\.infyp-[\w-]+)')
    RE_CUSTOM_PROP = re.compile(r'(--infyp-[\w-]+)\s*:\s*([^;]+);')

    def __init__(self, filepath: Path, plugin_dir: Path):
        self.filepath = filepath
        self.plugin_dir = plugin_dir
        self.rel_path = relative_path(filepath, plugin_dir)
        self.content = filepath.read_text(encoding='utf-8', errors='replace')

    def parse_selectors(self) -> List[CSSSelector]:
        selectors = []
        seen = set()
        for m in self.RE_SELECTOR.finditer(self.content):
            sel = m.group(1)
            key = (sel, self.rel_path)
            if key not in seen:
                seen.add(key)
                selectors.append(CSSSelector(
                    selector=sel,
                    file=self.rel_path,
                    line=line_number_at(self.content, m.start())
                ))
        return selectors

    def parse_custom_props(self) -> List[CSSCustomProp]:
        props = []
        seen = set()
        for m in self.RE_CUSTOM_PROP.finditer(self.content):
            prop = m.group(1)
            key = (prop, self.rel_path)
            if key not in seen:
                seen.add(key)
                props.append(CSSCustomProp(
                    prop=prop,
                    value=m.group(2).strip(),
                    file=self.rel_path,
                    line=line_number_at(self.content, m.start())
                ))
        return props


# =============================================================================
# Codebase Index — Aggregator
# =============================================================================

class CodebaseIndex:
    """Walk a plugin directory and aggregate all parsed data."""

    def __init__(self, plugin_dir: Path, label: str):
        self.plugin_dir = plugin_dir
        self.label = label

        self.classes: List[ClassInfo] = []
        self.constants: List[ConstantInfo] = []
        self.hook_definitions: List[HookDefinition] = []
        self.hook_registrations: List[HookRegistration] = []
        self.ajax_endpoints: List[AjaxEndpoint] = []
        self.options: List[OptionReference] = []
        self.transients: List[MetaReference] = []
        self.post_meta: List[MetaReference] = []
        self.user_meta: List[MetaReference] = []
        self.db_tables: List[DatabaseTable] = []
        self.js_modules: List[JSModuleInfo] = []
        self.css_selectors: List[CSSSelector] = []
        self.css_custom_props: List[CSSCustomProp] = []

    def scan(self):
        """Scan all files and populate data."""
        if not self.plugin_dir.exists():
            print_warn(f"Plugin directory not found: {self.plugin_dir}")
            return

        # PHP files
        php_files = walk_files(self.plugin_dir, PHP_EXTENSIONS)
        for f in php_files:
            parser = PHPFileParser(f, self.plugin_dir)
            self.classes.extend(parser.parse_classes())
            self.constants.extend(parser.parse_constants())
            self.hook_definitions.extend(parser.parse_hook_definitions())
            self.hook_registrations.extend(parser.parse_hook_registrations())
            self.ajax_endpoints.extend(parser.parse_ajax_endpoints())
            self.options.extend(parser.parse_options())
            self.transients.extend(parser.parse_transients())
            self.post_meta.extend(parser.parse_post_meta())
            self.user_meta.extend(parser.parse_user_meta())
            self.db_tables.extend(parser.parse_database_tables())

        # JS files (skip dist/min files — scan source only)
        js_files = walk_files(self.plugin_dir, JS_EXTENSIONS)
        for f in js_files:
            rel = relative_path(f, self.plugin_dir)
            if '/dist/' in rel or rel.endswith('.min.js') or '/vendor/' in rel:
                continue
            parser = JSFileParser(f, self.plugin_dir)
            info = parser.parse()
            if info.ajax_actions or info.exports or info.imports:
                self.js_modules.append(info)

        # CSS files (skip dist/min files — scan source only)
        css_files = walk_files(self.plugin_dir, CSS_EXTENSIONS)
        for f in css_files:
            rel = relative_path(f, self.plugin_dir)
            if '/dist/' in rel or rel.endswith('.min.css'):
                continue
            parser = CSSFileParser(f, self.plugin_dir)
            self.css_selectors.extend(parser.parse_selectors())
            self.css_custom_props.extend(parser.parse_custom_props())

    def print_stats(self):
        """Print extraction counts."""
        filters = [h for h in self.hook_definitions if h.hook_type == 'filter']
        actions = [h for h in self.hook_definitions if h.hook_type == 'action']
        print(f"    Classes:           {len(self.classes)}")
        print(f"    Methods:           {sum(len(c.methods) for c in self.classes)}")
        print(f"    Constants:         {len(self.constants)}")
        print(f"    Filter defs:       {len(filters)}")
        print(f"    Action defs:       {len(actions)}")
        print(f"    Hook registrations:{len(self.hook_registrations)}")
        print(f"    AJAX endpoints:    {len(self.ajax_endpoints)}")
        print(f"    Options:           {len(set(o.key for o in self.options))}")
        print(f"    Transients:        {len(set(t.key for t in self.transients))}")
        print(f"    Post meta keys:    {len(set(m.key for m in self.post_meta))}")
        print(f"    User meta keys:    {len(set(m.key for m in self.user_meta))}")
        print(f"    DB tables:         {len(set(t.table_name for t in self.db_tables))}")
        print(f"    JS modules:        {len(self.js_modules)}")
        print(f"    JS AJAX actions:   {sum(len(m.ajax_actions) for m in self.js_modules)}")
        print(f"    CSS selectors:     {len(self.css_selectors)}")
        print(f"    CSS custom props:  {len(self.css_custom_props)}")


# =============================================================================
# Cross Referencing
# =============================================================================

class CrossReferencer:
    """Match pro hook registrations to free hook definitions."""

    def __init__(self, free: CodebaseIndex, pro: CodebaseIndex):
        self.free = free
        self.pro = pro

    def find_connections(self) -> List[dict]:
        """Find pro add_filter/add_action calls that match free apply_filters/do_action."""
        connections = []

        # Build lookup of free hook definitions by name
        free_hooks = {}
        for h in self.free.hook_definitions:
            if h.hook_name not in free_hooks:
                free_hooks[h.hook_name] = h

        # Match pro registrations to free definitions
        for reg in self.pro.hook_registrations:
            hook_name = reg.hook_name
            # Skip WordPress core hooks
            if not hook_name.startswith(PREFIX) and not hook_name.startswith('infyp'):
                continue
            free_def = free_hooks.get(hook_name)
            if free_def:
                connections.append({
                    'hook_name': hook_name,
                    'hook_type': reg.hook_type,
                    'pro_callback': reg.callback,
                    'pro_priority': reg.priority,
                    'free_file': free_def.file,
                    'free_line': free_def.line,
                    'pro_file': reg.file,
                    'pro_line': reg.line,
                })

        # Sort by hook name
        connections.sort(key=lambda c: c['hook_name'])
        return connections


# =============================================================================
# Markdown Writer
# =============================================================================

class MarkdownWriter:
    """Generate markdown index files from parsed data."""

    def __init__(self, timestamp: str):
        self.timestamp = timestamp

    def _header(self) -> str:
        return (
            f"<!-- AUTO-GENERATED by build/generate-code-index.py — DO NOT EDIT -->\n"
            f"<!-- Generated: {self.timestamp} -->\n"
            f"<!-- Re-run: python3 build/generate-code-index.py -->\n\n"
        )

    def generate_class_index(self, free: CodebaseIndex, pro: CodebaseIndex) -> str:
        """Generate class-index.md with classes, methods, and constants."""
        out = self._header()
        out += "# Class & Constant Index\n\n"

        for idx, label, constants, classes in [
            (free, 'Free Plugin', free.constants, free.classes),
            (pro, 'Pro Plugin', pro.constants, pro.classes),
        ]:
            if not classes and not constants:
                continue

            # Group classes by namespace subpackage
            by_ns = {}
            for cls in classes:
                ns = cls.namespace or '(global)'
                by_ns.setdefault(ns, []).append(cls)

            out += f"## {label} ({len(classes)} classes)\n\n"

            # Constants
            if constants:
                out += "### Constants\n\n"
                out += "| Constant | Value | Location |\n"
                out += "|----------|-------|----------|\n"
                for c in sorted(constants, key=lambda x: x.name):
                    val = c.value[:50] + '...' if len(c.value) > 50 else c.value
                    val = val.replace('|', '\\|')
                    out += f"| `{c.name}` | `{val}` | `{c.file}:{c.line}` |\n"
                out += "\n"

            # Classes
            for ns in sorted(by_ns.keys()):
                ns_classes = sorted(by_ns[ns], key=lambda c: c.name)
                out += f"### {ns} ({len(ns_classes)} classes)\n\n"

                for cls in ns_classes:
                    extends_str = f" extends {cls.extends}" if cls.extends else ''
                    out += f"#### {cls.name}{extends_str} — `{cls.file}:{cls.line}`\n\n"

                    if cls.methods:
                        out += "| Vis | Method | Line |\n"
                        out += "|-----|--------|------|\n"
                        for m in cls.methods:
                            vis = m.visibility[:3]
                            if m.is_static:
                                vis += ' static'
                            params = m.params[:60] + '...' if len(m.params) > 60 else m.params
                            params = params.replace('|', '\\|')
                            out += f"| {vis} | `{m.name}({params})` | {m.line} |\n"
                        out += "\n"
                    else:
                        out += "_No public/private/protected methods found._\n\n"

        return out

    def generate_hooks_map(self, free: CodebaseIndex, pro: CodebaseIndex) -> str:
        """Generate hooks-map.md with all hook definitions (apply_filters/do_action)."""
        out = self._header()
        out += "# Hook Definitions Map\n\n"
        out += "All `apply_filters()` and `do_action()` calls that define extension points.\n\n"

        for idx, label in [(free, 'Free Plugin'), (pro, 'Pro Plugin')]:
            hooks = idx.hook_definitions
            if not hooks:
                continue

            filters = sorted([h for h in hooks if h.hook_type == 'filter'], key=lambda h: h.hook_name)
            actions = sorted([h for h in hooks if h.hook_type == 'action'], key=lambda h: h.hook_name)

            out += f"## {label}\n\n"

            if filters:
                out += f"### Filters — apply_filters ({len(filters)})\n\n"
                out += "| Hook | File | Line | Params |\n"
                out += "|------|------|------|--------|\n"
                for h in filters:
                    params = h.params or '—'
                    out += f"| `{h.hook_name}` | `{h.file}` | {h.line} | {params} |\n"
                out += "\n"

            if actions:
                out += f"### Actions — do_action ({len(actions)})\n\n"
                out += "| Hook | File | Line | Params |\n"
                out += "|------|------|------|--------|\n"
                for h in actions:
                    params = h.params or '—'
                    out += f"| `{h.hook_name}` | `{h.file}` | {h.line} | {params} |\n"
                out += "\n"

        return out

    def generate_hook_registrations(self, free: CodebaseIndex, pro: CodebaseIndex) -> str:
        """Generate hook-registrations.md with all add_filter/add_action calls."""
        out = self._header()
        out += "# Hook Registrations\n\n"
        out += "All `add_filter()` and `add_action()` calls registering callbacks on `infyp_*` hooks.\n\n"

        for idx, label in [(free, 'Free Plugin'), (pro, 'Pro Plugin')]:
            regs = idx.hook_registrations
            if not regs:
                continue

            # Filter to only infyp_ hooks (not wp_ajax_ etc.)
            infyp_regs = [r for r in regs if r.hook_name.startswith(PREFIX) or r.hook_name.startswith('infyp')]
            if not infyp_regs:
                continue

            filters = sorted([r for r in infyp_regs if r.hook_type == 'filter'], key=lambda r: r.hook_name)
            actions = sorted([r for r in infyp_regs if r.hook_type == 'action'], key=lambda r: r.hook_name)

            out += f"## {label}\n\n"

            if filters:
                out += f"### Filters — add_filter ({len(filters)})\n\n"
                out += "| Hook | Callback | Pri | Location |\n"
                out += "|------|----------|-----|----------|\n"
                for r in filters:
                    out += f"| `{r.hook_name}` | `{r.callback}` | {r.priority} | `{r.file}:{r.line}` |\n"
                out += "\n"

            if actions:
                out += f"### Actions — add_action ({len(actions)})\n\n"
                out += "| Hook | Callback | Pri | Location |\n"
                out += "|------|----------|-----|----------|\n"
                for r in actions:
                    out += f"| `{r.hook_name}` | `{r.callback}` | {r.priority} | `{r.file}:{r.line}` |\n"
                out += "\n"

        return out

    def generate_ajax_endpoints(self, free: CodebaseIndex, pro: CodebaseIndex) -> str:
        """Generate ajax-endpoints.md."""
        out = self._header()
        out += "# AJAX Endpoints\n\n"
        out += "All registered `wp_ajax_infyp_*` handlers.\n\n"

        for idx, label in [(free, 'Free Plugin'), (pro, 'Pro Plugin')]:
            endpoints = idx.ajax_endpoints
            if not endpoints:
                continue

            out += f"## {label} ({len(endpoints)} endpoints)\n\n"
            out += "| Action | Handler | Class | Type | Location |\n"
            out += "|--------|---------|-------|------|----------|\n"
            for e in sorted(endpoints, key=lambda x: x.action):
                out += f"| `{e.action}` | `{e.handler_method}` | `{e.handler_class}` | {e.registration_type} | `{e.file}:{e.line}` |\n"
            out += "\n"

        # JS-side AJAX actions
        all_js_actions = []
        for idx, label in [(free, 'Free Plugin'), (pro, 'Pro Plugin')]:
            for mod in idx.js_modules:
                for action in mod.ajax_actions:
                    all_js_actions.append((label, action))

        if all_js_actions:
            out += "## JS-side AJAX Calls\n\n"
            out += "AJAX actions referenced in JavaScript files.\n\n"
            out += "| Action | JS File | Line |\n"
            out += "|--------|---------|------|\n"
            for label, action in sorted(all_js_actions, key=lambda x: x[1].action):
                out += f"| `{action.action}` | `{action.file}` | {action.line} |\n"
            out += "\n"

        return out

    def generate_options_meta(self, free: CodebaseIndex, pro: CodebaseIndex) -> str:
        """Generate options-meta.md with options, transients, and meta keys."""
        out = self._header()
        out += "# Options, Transients & Meta Keys\n\n"

        for idx, label in [(free, 'Free Plugin'), (pro, 'Pro Plugin')]:
            has_data = idx.options or idx.transients or idx.post_meta or idx.user_meta
            if not has_data:
                continue

            out += f"## {label}\n\n"

            # Options — deduplicate by key, aggregate locations
            if idx.options:
                options_by_key: Dict[str, List[OptionReference]] = {}
                for o in idx.options:
                    options_by_key.setdefault(o.key, []).append(o)

                out += f"### wp_options ({len(options_by_key)} keys)\n\n"
                out += "| Option Key | Operations | Locations |\n"
                out += "|------------|------------|----------|\n"
                for key in sorted(options_by_key.keys()):
                    refs = options_by_key[key]
                    ops = sorted(set(r.operation for r in refs))
                    locs = sorted(set(f"`{r.file}:{r.line}`" for r in refs))
                    out += f"| `{key}` | {', '.join(ops)} | {', '.join(locs[:5])}"
                    if len(locs) > 5:
                        out += f" +{len(locs)-5} more"
                    out += " |\n"
                out += "\n"

            # Transients
            if idx.transients:
                trans_by_key: Dict[str, List[MetaReference]] = {}
                for t in idx.transients:
                    trans_by_key.setdefault(t.key, []).append(t)

                out += f"### Transients ({len(trans_by_key)} keys)\n\n"
                out += "| Transient Key | Operations | Locations |\n"
                out += "|---------------|------------|----------|\n"
                for key in sorted(trans_by_key.keys()):
                    refs = trans_by_key[key]
                    ops = sorted(set(r.operation for r in refs))
                    locs = sorted(set(f"`{r.file}:{r.line}`" for r in refs))
                    out += f"| `{key}` | {', '.join(ops)} | {', '.join(locs[:5])}"
                    if len(locs) > 5:
                        out += f" +{len(locs)-5} more"
                    out += " |\n"
                out += "\n"

            # Post meta
            if idx.post_meta:
                meta_by_key: Dict[str, List[MetaReference]] = {}
                for m in idx.post_meta:
                    meta_by_key.setdefault(m.key, []).append(m)

                out += f"### Post Meta ({len(meta_by_key)} keys)\n\n"
                out += "| Meta Key | Operations | Locations |\n"
                out += "|----------|------------|----------|\n"
                for key in sorted(meta_by_key.keys()):
                    refs = meta_by_key[key]
                    ops = sorted(set(r.operation for r in refs))
                    locs = sorted(set(f"`{r.file}:{r.line}`" for r in refs))
                    out += f"| `{key}` | {', '.join(ops)} | {', '.join(locs[:5])}"
                    if len(locs) > 5:
                        out += f" +{len(locs)-5} more"
                    out += " |\n"
                out += "\n"

            # User meta
            if idx.user_meta:
                umeta_by_key: Dict[str, List[MetaReference]] = {}
                for m in idx.user_meta:
                    umeta_by_key.setdefault(m.key, []).append(m)

                out += f"### User Meta ({len(umeta_by_key)} keys)\n\n"
                out += "| Meta Key | Operations | Locations |\n"
                out += "|----------|------------|----------|\n"
                for key in sorted(umeta_by_key.keys()):
                    refs = umeta_by_key[key]
                    ops = sorted(set(r.operation for r in refs))
                    locs = sorted(set(f"`{r.file}:{r.line}`" for r in refs))
                    out += f"| `{key}` | {', '.join(ops)} | {', '.join(locs[:5])}"
                    if len(locs) > 5:
                        out += f" +{len(locs)-5} more"
                    out += " |\n"
                out += "\n"

        return out

    def generate_database_tables(self, free: CodebaseIndex, pro: CodebaseIndex) -> str:
        """Generate database-tables.md."""
        out = self._header()
        out += "# Database Tables\n\n"
        out += "Custom tables using `$wpdb->prefix` with plugin-specific names.\n\n"

        for idx, label in [(free, 'Free Plugin'), (pro, 'Pro Plugin')]:
            tables = idx.db_tables
            if not tables:
                continue

            # Group by table name
            by_name: Dict[str, List[DatabaseTable]] = {}
            for t in tables:
                by_name.setdefault(t.table_name, []).append(t)

            out += f"## {label} ({len(by_name)} tables)\n\n"
            out += "| Table | Referenced In |\n"
            out += "|-------|---------------|\n"
            for name in sorted(by_name.keys()):
                refs = by_name[name]
                locs = sorted(set(f"`{r.file}:{r.line}`" for r in refs))
                out += f"| `{{prefix}}{name}` | {', '.join(locs[:5])}"
                if len(locs) > 5:
                    out += f" +{len(locs)-5} more"
                out += " |\n"
            out += "\n"

        return out

    def generate_js_modules(self, free: CodebaseIndex, pro: CodebaseIndex) -> str:
        """Generate js-modules.md."""
        out = self._header()
        out += "# JavaScript Modules\n\n"

        for idx, label in [(free, 'Free Plugin'), (pro, 'Pro Plugin')]:
            modules = idx.js_modules
            if not modules:
                continue

            out += f"## {label} ({len(modules)} modules)\n\n"

            for mod in sorted(modules, key=lambda m: m.file):
                out += f"### `{mod.file}`\n\n"
                if mod.exports:
                    out += f"**Exports:** {', '.join(f'`{e}`' for e in mod.exports)}\n\n"
                if mod.imports:
                    out += f"**Imports:** {', '.join(f'`{i}`' for i in mod.imports)}\n\n"
                if mod.ajax_actions:
                    out += "**AJAX Actions:**\n\n"
                    out += "| Action | Line |\n"
                    out += "|--------|------|\n"
                    for a in mod.ajax_actions:
                        out += f"| `{a.action}` | {a.line} |\n"
                    out += "\n"

        return out

    def generate_css_index(self, free: CodebaseIndex, pro: CodebaseIndex) -> str:
        """Generate css-index.md."""
        out = self._header()
        out += "# CSS Index\n\n"
        out += "All `.infyp-*` class selectors and `--infyp-*` custom properties.\n\n"

        for idx, label in [(free, 'Free Plugin'), (pro, 'Pro Plugin')]:
            has_data = idx.css_selectors or idx.css_custom_props
            if not has_data:
                continue

            out += f"## {label}\n\n"

            if idx.css_custom_props:
                out += f"### Custom Properties ({len(idx.css_custom_props)})\n\n"
                out += "| Property | Value | File | Line |\n"
                out += "|----------|-------|------|------|\n"
                for p in sorted(idx.css_custom_props, key=lambda x: x.prop):
                    val = p.value[:40] + '...' if len(p.value) > 40 else p.value
                    val = val.replace('|', '\\|')
                    out += f"| `{p.prop}` | `{val}` | `{p.file}` | {p.line} |\n"
                out += "\n"

            if idx.css_selectors:
                # Group selectors by file
                by_file: Dict[str, List[CSSSelector]] = {}
                for s in idx.css_selectors:
                    by_file.setdefault(s.file, []).append(s)

                out += f"### Class Selectors ({len(idx.css_selectors)})\n\n"
                for file in sorted(by_file.keys()):
                    sels = by_file[file]
                    sel_list = ', '.join(f'`{s.selector}`' for s in sorted(sels, key=lambda x: x.line))
                    out += f"**`{file}`**: {sel_list}\n\n"

        return out

    def generate_pro_free_connections(self, connections: List[dict]) -> str:
        """Generate pro-free-connections.md."""
        out = self._header()
        out += "# Pro ↔ Free Plugin Connections\n\n"
        out += "Pro plugin hook registrations that connect to free plugin hook definitions.\n\n"

        if not connections:
            out += "_No connections found (pro plugin may not be available)._\n"
            return out

        filters = [c for c in connections if c['hook_type'] == 'filter']
        actions = [c for c in connections if c['hook_type'] == 'action']

        if filters:
            out += f"## Filters ({len(filters)})\n\n"
            out += "| Hook | Pro Callback | Pri | Free Def | Pro Reg |\n"
            out += "|------|-------------|-----|----------|----------|\n"
            for c in filters:
                out += (
                    f"| `{c['hook_name']}` "
                    f"| `{c['pro_callback']}` "
                    f"| {c['pro_priority']} "
                    f"| `{c['free_file']}:{c['free_line']}` "
                    f"| `{c['pro_file']}:{c['pro_line']}` |\n"
                )
            out += "\n"

        if actions:
            out += f"## Actions ({len(actions)})\n\n"
            out += "| Hook | Pro Callback | Pri | Free Def | Pro Reg |\n"
            out += "|------|-------------|-----|----------|----------|\n"
            for c in actions:
                out += (
                    f"| `{c['hook_name']}` "
                    f"| `{c['pro_callback']}` "
                    f"| {c['pro_priority']} "
                    f"| `{c['free_file']}:{c['free_line']}` "
                    f"| `{c['pro_file']}:{c['pro_line']}` |\n"
                )
            out += "\n"

        return out

    def generate_index(self, free: CodebaseIndex, pro: CodebaseIndex, connections: List[dict]) -> str:
        """Generate INDEX.md — table of contents for the generated directory."""
        out = self._header()
        out += "# Generated Code Index\n\n"
        out += "Auto-generated navigational indexes for AI agents. Each file provides\n"
        out += "exact `file:line` references for quick code navigation.\n\n"
        out += "## Index Files\n\n"
        out += "| File | Contents |\n"
        out += "|------|----------|\n"
        out += "| [class-index.md](class-index.md) | Classes, methods, and constants with line numbers |\n"
        out += "| [hooks-map.md](hooks-map.md) | Hook definitions (apply_filters, do_action) |\n"
        out += "| [hook-registrations.md](hook-registrations.md) | Hook registrations (add_filter, add_action) |\n"
        out += "| [ajax-endpoints.md](ajax-endpoints.md) | AJAX endpoint registrations (PHP + JS) |\n"
        out += "| [options-meta.md](options-meta.md) | wp_options, transients, post/user meta keys |\n"
        out += "| [database-tables.md](database-tables.md) | Custom database tables |\n"
        out += "| [js-modules.md](js-modules.md) | JavaScript module exports, imports, AJAX calls |\n"
        out += "| [css-index.md](css-index.md) | CSS class selectors and custom properties |\n"
        out += "| [pro-free-connections.md](pro-free-connections.md) | Pro ↔ Free hook connections |\n"
        out += "\n"

        out += "## Quick Stats\n\n"

        for idx, label in [(free, 'Free'), (pro, 'Pro')]:
            if not idx.classes and not idx.hook_definitions:
                continue
            filters = len([h for h in idx.hook_definitions if h.hook_type == 'filter'])
            actions = len([h for h in idx.hook_definitions if h.hook_type == 'action'])
            out += f"**{label}**: "
            out += f"{len(idx.classes)} classes, "
            out += f"{filters} filters, "
            out += f"{actions} actions, "
            out += f"{len(idx.ajax_endpoints)} AJAX endpoints\n\n"

        if connections:
            out += f"**Connections**: {len(connections)} pro→free hook connections\n\n"

        return out


# =============================================================================
# CLAUDE.md Generator
# =============================================================================

def generate_claude_md(free: CodebaseIndex, pro: CodebaseIndex, plugin_type: str) -> str:
    """Generate CLAUDE.md for a plugin root."""
    is_free = plugin_type == 'free'

    content = ""
    content += "# Infy - AI Image SEO Optimizer"
    if not is_free:
        content += " Pro"
    content += "\n\n"

    if is_free:
        content += "WordPress plugin that optimizes image SEO using AI (OpenAI, Google Gemini, Anthropic Claude).\n\n"
    else:
        content += "Pro extension for the free Infy AI Image SEO Optimizer plugin.\n\n"

    content += "## Quick Reference\n\n"
    content += f"- **Namespace**: `{NAMESPACE_ROOT}`\n"
    content += f"- **Class prefix**: `{CLASS_PREFIX}`\n"
    content += f"- **Hook/option prefix**: `{PREFIX}`\n"

    if is_free:
        content += "- **Text domain**: `infy-ai-image-seo-optimizer`\n"
        content += "- **Main file**: `infy-ai-image-seo-optimizer.php`\n"
    else:
        content += "- **Text domain**: `infy-ai-image-seo-optimizer-pro`\n"
        content += "- **Main file**: `infy-ai-image-seo-optimizer-pro.php`\n"

    content += "\n## Build Commands\n\n"
    content += "```bash\n"
    if is_free:
        content += "npm run build                          # Build CSS + JS assets\n"
        content += "python3 build/build.py                 # Full production ZIP\n"
        content += "python3 build/generate-code-index.py   # Regenerate code indexes\n"
    else:
        content += "npm run build                          # Build Pro assets\n"
    content += "```\n\n"

    content += "## Code Index (Auto-Generated)\n\n"
    content += "Navigate the codebase using these generated indexes with exact `file:line` references:\n\n"
    content += "| Index | What's Inside |\n"
    content += "|-------|---------------|\n"
    content += "| [class-index](docs/claude/generated/class-index.md) | Classes, methods, constants |\n"
    content += "| [hooks-map](docs/claude/generated/hooks-map.md) | apply_filters / do_action definitions |\n"
    content += "| [hook-registrations](docs/claude/generated/hook-registrations.md) | add_filter / add_action calls |\n"
    content += "| [ajax-endpoints](docs/claude/generated/ajax-endpoints.md) | AJAX handlers (PHP + JS) |\n"
    content += "| [options-meta](docs/claude/generated/options-meta.md) | wp_options, transients, meta keys |\n"
    content += "| [database-tables](docs/claude/generated/database-tables.md) | Custom DB tables |\n"
    content += "| [js-modules](docs/claude/generated/js-modules.md) | JS module structure |\n"
    content += "| [css-index](docs/claude/generated/css-index.md) | CSS selectors and custom properties |\n"
    content += "| [pro-free-connections](docs/claude/generated/pro-free-connections.md) | Pro ↔ Free hook wiring |\n"
    content += "\n"

    if is_free:
        content += "## Architecture Docs (Manual)\n\n"
        content += "In-depth architectural documentation (not auto-generated):\n\n"
        content += "| Doc | Topic |\n"
        content += "|-----|-------|\n"
        content += "| [architecture](docs/claude/architecture.md) | System architecture overview |\n"
        content += "| [php-classes](docs/claude/php-classes.md) | PHP class responsibilities |\n"
        content += "| [admin-pages](docs/claude/admin-pages.md) | Admin page structure |\n"
        content += "| [ajax-endpoints](docs/claude/ajax-endpoints.md) | AJAX endpoint details |\n"
        content += "| [hooks-reference](docs/claude/hooks-reference.md) | Hook usage guide |\n"
        content += "| [options-meta-reference](docs/claude/options-meta-reference.md) | Options/meta guide |\n"
        content += "| [database-schema](docs/claude/database-schema.md) | Database schema details |\n"
        content += "| [javascript-modules](docs/claude/javascript-modules.md) | JS architecture |\n"
        content += "| [css-architecture](docs/claude/css-architecture.md) | CSS framework layers |\n"
        content += "| [build-system](docs/claude/build-system.md) | Build pipeline |\n"
        content += "| [pro-integration](docs/claude/pro-integration.md) | Pro plugin integration |\n"
        content += "\n"

    content += "## Key Architecture\n\n"

    if is_free:
        content += "- **Singleton pattern**: Manager classes (`INFYP_Cache_Manager`, `INFYP_Security_Manager`, etc.) use `get_instance()`\n"
        content += "- **Static methods**: `INFYP_AI_Optimizer` uses static methods — no instantiation needed\n"
        content += "- **AJAX registration**: `INFYP_Ajax_Handlers` uses array-based `$handlers = [action => method]` pattern\n"
        content += "- **Cache tiers**: Memory (static array) → Transient → DB query\n"
        content += "- **CSS framework**: `assets/css/framework/` (base) + `assets/css/plugin/` (features) → `assets/css/dist/infyp.min.css`\n"
        content += "- **JS bundles**: `assets/js/modules/` (source) → `assets/js/dist/infyp-admin.min.js`\n"
        content += "- **Page builders**: Adapter pattern in `includes/Core/PageBuilders/` (Gutenberg, Elementor, Visual Composer)\n"
    else:
        content += "- **Requires free plugin**: Checks for `IPAIS\\Core\\INFYP_Script_Manager` class\n"
        content += "- **License gated**: Features load only when `$this->license->has_valid_license()`\n"
        content += "- **Hook-based integration**: Extends free plugin via `add_filter`/`add_action` on `infyp_*` hooks\n"
        content += "- **Priority interception**: Pro AJAX handlers use priority 5 to run before free handlers (priority 10)\n"
        content += "- **SDK**: `infyp-sdk/` handles license validation and plugin updates\n"

    content += "\n## Directory Layout\n\n"
    content += "```\n"

    idx = free if is_free else pro
    if is_free:
        content += "includes/\n"
        content += "  Admin/          — Admin pages, settings, meta boxes, notices\n"
        content += "  Ajax/           — AJAX request handlers\n"
        content += "  Core/           — AI optimizer, caching, security, encryption, scripts\n"
        content += "  Core/PageBuilders/ — Page builder adapters\n"
        content += "  Cron/           — Scheduled tasks (Search Console sync, stats)\n"
        content += "  Database/       — DB operations, changelog, optimizer\n"
        content += "  Dev/            — Development helpers (mock data)\n"
        content += "  Helpers/        — CSS helper, notices, debug translations\n"
        content += "  Tables/         — WP_List_Table subclasses\n"
        content += "  views/          — PHP template files\n"
        content += "assets/\n"
        content += "  css/            — Source + dist CSS\n"
        content += "  js/             — Source + dist JS\n"
        content += "  images/         — Plugin images\n"
    else:
        content += "includes/\n"
        content += "  Core/           — Caption/description optimizer, retry, cache, errors\n"
        content += "  Features/       — AI models, bulk optimize, CPTs, WooCommerce, uploads\n"
        content += "  Ajax/           — Pro AJAX handlers\n"
        content += "infyp-sdk/        — License management SDK\n"

    content += "```\n"

    return content


# =============================================================================
# Main
# =============================================================================

def main():
    parser = argparse.ArgumentParser(
        description='Generate code index files for AI agent navigation.'
    )
    parser.add_argument('--free', action='store_true', help='Index free plugin only')
    parser.add_argument('--pro', action='store_true', help='Index pro plugin only')
    parser.add_argument('--stats', action='store_true', help='Print extraction counts only')
    parser.add_argument('--claude-md', action='store_true', help='Also generate CLAUDE.md at plugin roots')
    parser.add_argument('--dry-run', action='store_true', help='Preview without writing files')

    args = parser.parse_args()

    # Default: both plugins
    do_free = not args.pro or args.free
    do_pro = not args.free or args.pro
    # If neither flag, do both
    if not args.free and not args.pro:
        do_free = True
        do_pro = True

    timestamp = datetime.now().strftime('%Y-%m-%dT%H:%M:%S')

    print_header("Code Index Generator")
    print()

    # Scan plugins
    free_index = CodebaseIndex(FREE_PLUGIN_DIR, 'Free Plugin')
    pro_index = CodebaseIndex(PRO_PLUGIN_DIR, 'Pro Plugin')

    if do_free:
        print_info(f"Scanning free plugin: {FREE_PLUGIN_DIR}")
        free_index.scan()
        print_success("Free plugin scanned")

    if do_pro:
        if PRO_PLUGIN_DIR.exists():
            print_info(f"Scanning pro plugin: {PRO_PLUGIN_DIR}")
            pro_index.scan()
            print_success("Pro plugin scanned")
        else:
            print_warn(f"Pro plugin not found at {PRO_PLUGIN_DIR}")
            do_pro = False

    # Stats mode
    if args.stats:
        print()
        if do_free:
            print_color(Colors.BOLD, "  Free Plugin Stats:")
            free_index.print_stats()
        if do_pro:
            print()
            print_color(Colors.BOLD, "  Pro Plugin Stats:")
            pro_index.print_stats()
        print()
        return

    # Cross-reference
    connections = []
    if do_free and do_pro:
        print_info("Cross-referencing pro ↔ free connections")
        xref = CrossReferencer(free_index, pro_index)
        connections = xref.find_connections()
        print_success(f"Found {len(connections)} connections")

    # Generate markdown
    writer = MarkdownWriter(timestamp)

    files_to_write: List[Tuple[Path, str]] = []

    # Generate index files for each plugin that's being indexed
    for plugin_dir, idx, label, do_it in [
        (FREE_PLUGIN_DIR, free_index, 'free', do_free),
        (PRO_PLUGIN_DIR, pro_index, 'pro', do_pro),
    ]:
        if not do_it:
            continue

        out_dir = plugin_dir / OUTPUT_SUBDIR

        # For the index files, pass both indexes so we get a complete picture
        # But the "other" index may be empty if only one plugin was scanned
        files_to_write.append((out_dir / 'INDEX.md', writer.generate_index(free_index, pro_index, connections)))
        files_to_write.append((out_dir / 'class-index.md', writer.generate_class_index(free_index, pro_index)))
        files_to_write.append((out_dir / 'hooks-map.md', writer.generate_hooks_map(free_index, pro_index)))
        files_to_write.append((out_dir / 'hook-registrations.md', writer.generate_hook_registrations(free_index, pro_index)))
        files_to_write.append((out_dir / 'ajax-endpoints.md', writer.generate_ajax_endpoints(free_index, pro_index)))
        files_to_write.append((out_dir / 'options-meta.md', writer.generate_options_meta(free_index, pro_index)))
        files_to_write.append((out_dir / 'database-tables.md', writer.generate_database_tables(free_index, pro_index)))
        files_to_write.append((out_dir / 'js-modules.md', writer.generate_js_modules(free_index, pro_index)))
        files_to_write.append((out_dir / 'css-index.md', writer.generate_css_index(free_index, pro_index)))
        files_to_write.append((out_dir / 'pro-free-connections.md', writer.generate_pro_free_connections(connections)))

    # CLAUDE.md
    if args.claude_md:
        if do_free:
            files_to_write.append((
                FREE_PLUGIN_DIR / 'CLAUDE.md',
                generate_claude_md(free_index, pro_index, 'free')
            ))
        if do_pro:
            files_to_write.append((
                PRO_PLUGIN_DIR / 'CLAUDE.md',
                generate_claude_md(free_index, pro_index, 'pro')
            ))

    # Write files
    print()
    if args.dry_run:
        print_color(Colors.YELLOW, "  DRY RUN — no files written")
        print()
        for filepath, content in files_to_write:
            lines = content.count('\n')
            print(f"    Would write: {filepath} ({lines} lines)")
        print()
        return

    for filepath, content in files_to_write:
        filepath.parent.mkdir(parents=True, exist_ok=True)
        filepath.write_text(content, encoding='utf-8')
        rel = filepath.relative_to(filepath.parent.parent.parent.parent) if 'generated' in str(filepath) else filepath.name
        print_success(f"Wrote {rel}")

    print()
    total = len(files_to_write)
    print_color(Colors.GREEN, f"  Done! Generated {total} files.")
    print()


if __name__ == '__main__':
    main()
