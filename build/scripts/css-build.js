#!/usr/bin/env node

/**
 * CSS Build Script
 *
 * Concatenates and optionally minifies CSS files for production.
 * Handles both framework and plugin CSS with proper ordering.
 *
 * Usage:
 *   node scripts/css-build.js           # Development build (unminified)
 *   node scripts/css-build.js --minify  # Production build (minified)
 *
 * Output:
 *   assets/css/dist/framework.css       # Framework styles
 *   assets/css/dist/plugin.css          # Plugin-specific styles
 *   assets/css/dist/admin.css           # Combined admin styles
 *   assets/css/dist/*.min.css           # Minified versions (with --minify)
 */

const fs = require('fs');
const path = require('path');
const postcss = require('postcss');
const autoprefixer = require('autoprefixer');
const postcssImport = require('postcss-import');
const CleanCSS = require('clean-css');

// Configuration
// Script is now in build/scripts/, so plugin root is two levels up
const PLUGIN_ROOT = path.join(__dirname, '..', '..');
const CONFIG = {
    srcDir: path.join(PLUGIN_ROOT, 'assets', 'css'),
    distDir: path.join(PLUGIN_ROOT, 'assets', 'css', 'dist'),

    // Framework files in order
    framework: [
        'framework/variables.css',
        'framework/components/forms.css',
        'framework/components/modals.css',
        'framework/components/badges.css',
        'framework/components/info-boxes.css',
    ],

    // Plugin files in order
    plugin: [
        // Core admin styles
        'admin-style.css',
        'modals.css',
        'optimize-icon.css',
        // Plugin component styles
        'plugin/image-table.css',
        'plugin/image-table-responsive.css',
        'plugin/search-filters.css',
        'plugin/post-type-filters.css',
        'plugin/keywords.css',
        'plugin/loading-states.css',
        'plugin/seo-score-display.css',
        'plugin/seo-score-page.css',
        'plugin/seo-score-references.css',
        'plugin/usage-badges.css',
        // Page-specific styles
        'plugin/api-keys.css',
        'plugin/api-usage.css',
        'plugin/rest-api.css',
        'plugin/dashboard.css',
        'plugin/settings.css',
        'plugin/account-settings.css',
        'plugin/debug-page.css',
        'plugin/search-console.css',
        'plugin/redirects.css',
        'plugin/setup-wizard.css'
    ]
};

// Check for minify flag
const shouldMinify = process.argv.includes('--minify');

/**
 * Read and concatenate CSS files
 */
function readFiles(files, baseDir) {
    let content = '';
    const missing = [];

    for (const file of files) {
        const filePath = path.join(baseDir, file);
        if (fs.existsSync(filePath)) {
            const fileContent = fs.readFileSync(filePath, 'utf8');
            content += `/* Source: ${file} */\n${fileContent}\n\n`;
        } else {
            missing.push(file);
        }
    }

    if (missing.length > 0) {
        console.warn(`Warning: Missing files: ${missing.join(', ')}`);
    }

    return content;
}

/**
 * Process CSS with PostCSS
 */
async function processCSS(css, filename) {
    // Remove @import statements since we're concatenating files directly
    // This prevents postcss-import from trying to resolve them
    css = css.replace(/@import\s+url\([^)]+\);?\s*/g, '/* @import removed - file already included */\n');

    const result = await postcss([
        autoprefixer()
    ]).process(css, {
        from: filename,
        to: filename
    });

    // Log any warnings
    result.warnings().forEach(warn => {
        console.warn(`PostCSS Warning: ${warn.text}`);
    });

    return result.css;
}

/**
 * Minify CSS
 *
 * Using conservative settings to preserve CSS cascade order.
 * Aggressive merging can break specificity and selector order.
 */
function minifyCSS(css) {
    const result = new CleanCSS({
        level: {
            1: {
                specialComments: 0,
                removeEmpty: true,
                removeWhitespace: true
            },
            2: {
                // Disable ALL aggressive optimizations that can break CSS order
                mergeSemantically: false,
                restructureRules: false,
                mergeAdjacentRules: false,  // Don't merge adjacent rules
                mergeIntoShorthands: true,
                mergeMedia: true,
                mergeNonAdjacentRules: false,  // Don't merge non-adjacent rules
                overrideProperties: false,  // Don't optimize override properties
                removeEmpty: true,
                removeDuplicateFontRules: true,
                removeDuplicateMediaBlocks: true,
                removeDuplicateRules: false,  // Keep duplicate rules (order matters!)
                removeUnusedAtRules: false,
                reduceNonAdjacentRules: false,  // Don't reduce non-adjacent rules
                skipProperties: []
            }
        }
    }).minify(css);

    if (result.errors.length > 0) {
        console.error('Minification errors:', result.errors);
    }

    if (result.warnings.length > 0) {
        console.warn('Minification warnings:', result.warnings);
    }

    return result.styles;
}

/**
 * Write file with stats
 */
function writeFile(filepath, content) {
    fs.writeFileSync(filepath, content, 'utf8');
    const stats = fs.statSync(filepath);
    const sizeKB = (stats.size / 1024).toFixed(2);
    console.log(`  Created: ${path.basename(filepath)} (${sizeKB} KB)`);
}

/**
 * Main build function
 */
async function build() {
    console.log('\n🔨 CSS Build Script');
    console.log('==================\n');

    // Ensure dist directory exists
    if (!fs.existsSync(CONFIG.distDir)) {
        fs.mkdirSync(CONFIG.distDir, { recursive: true });
    }

    try {
        // Build framework CSS
        console.log('Building framework CSS...');
        let frameworkCSS = readFiles(CONFIG.framework, CONFIG.srcDir);
        frameworkCSS = await processCSS(frameworkCSS, 'framework.css');
        writeFile(path.join(CONFIG.distDir, 'framework.css'), frameworkCSS);

        if (shouldMinify) {
            const frameworkMin = minifyCSS(frameworkCSS);
            writeFile(path.join(CONFIG.distDir, 'framework.min.css'), frameworkMin);
        }

        // Build plugin CSS
        console.log('\nBuilding plugin CSS...');
        let pluginCSS = readFiles(CONFIG.plugin, CONFIG.srcDir);
        pluginCSS = await processCSS(pluginCSS, 'plugin.css');
        writeFile(path.join(CONFIG.distDir, 'plugin.css'), pluginCSS);

        if (shouldMinify) {
            const pluginMin = minifyCSS(pluginCSS);
            writeFile(path.join(CONFIG.distDir, 'plugin.min.css'), pluginMin);
        }

        // Build combined infyp CSS (renamed from admin for better error reporting)
        console.log('\nBuilding combined infyp CSS...');
        const adminCSS = frameworkCSS + '\n\n' + pluginCSS;
        writeFile(path.join(CONFIG.distDir, 'infyp.css'), adminCSS);

        if (shouldMinify) {
            const adminMin = minifyCSS(adminCSS);
            writeFile(path.join(CONFIG.distDir, 'infyp.min.css'), adminMin);
        }

        // Summary
        console.log('\n✅ Build complete!\n');

        if (shouldMinify) {
            // Calculate savings
            const origSize = fs.statSync(path.join(CONFIG.distDir, 'infyp.css')).size;
            const minSize = fs.statSync(path.join(CONFIG.distDir, 'infyp.min.css')).size;
            const savings = ((1 - minSize / origSize) * 100).toFixed(1);
            console.log(`📊 Minification savings: ${savings}%`);
        }

    } catch (error) {
        console.error('❌ Build failed:', error.message);
        process.exit(1);
    }
}

// Run build
build();
