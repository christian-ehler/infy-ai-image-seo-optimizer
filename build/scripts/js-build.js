#!/usr/bin/env node

/**
 * JavaScript Build Script
 *
 * Bundles JavaScript modules using Rollup for production.
 * Creates optimized bundles with minification support.
 *
 * WordPress.org Compliance:
 *   Both minified AND unminified versions are always generated.
 *   This ensures human-readable code is available as required by WordPress.org.
 *
 * Usage:
 *   node scripts/js-build.js           # Production build (minified + unminified)
 *   node scripts/js-build.js --dev     # Development build (unminified only)
 *
 * Output:
 *   assets/js/dist/infyp-admin.js        # Main admin bundle (unminified)
 *   assets/js/dist/infyp-admin.min.js    # Main admin bundle (minified)
 *   assets/js/dist/*.js                    # Standalone scripts (unminified)
 *   assets/js/dist/*.min.js                # Standalone scripts (minified)
 */

const fs = require('fs');
const path = require('path');
const { rollup } = require('rollup');
const terser = require('@rollup/plugin-terser');

// Configuration
const PLUGIN_ROOT = path.join(__dirname, '..', '..');
const CONFIG = {
    srcDir: path.join(PLUGIN_ROOT, 'assets', 'js'),
    distDir: path.join(PLUGIN_ROOT, 'assets', 'js', 'dist'),

    // Bundle definitions
    bundles: [
        {
            name: 'infyp-core',
            description: 'Core modules (API, config, scoring engine)',
            entry: 'bundles/core.js',
            modules: [
                'modules/api.js',
                'modules/core/config.js',
                'modules/core/event-manager.js',
                'modules/core/scoring-engine.js'
            ]
        },
        {
            name: 'infyp-ui',
            description: 'UI utilities and helpers',
            entry: 'bundles/ui.js',
            modules: [
                'modules/ui.js',
                'modules/ui/helpers.js',
                'modules/ui/notifications.js',
                'modules/ui/loading-states.js'
            ]
        },
        {
            name: 'infyp-table',
            description: 'Table functionality',
            entry: 'bundles/table.js',
            modules: [
                'modules/image-table.js',
                'modules/table/field-editor.js',
                'modules/table/pagination.js',
                'modules/table/filtering.js',
                'modules/table/bulk-selection.js',
                'modules/table/character-counter.js',
                'modules/table/live-search.js'
            ]
        },
        {
            name: 'infyp-modals',
            description: 'Modal dialogs',
            entry: 'bundles/modals.js',
            modules: [
                'modules/core/modal-loader.js',
                'modules/modals/base-modal.js',
                'modules/modals/usage-modal.js',
                'modules/modals/alt-text-modal.js',
                'modules/modals/title-modal.js',
                'modules/modals/filename-modal.js',
                'modules/modals/keywords-modal.js',
                'modules/modals/seo-score-modal.js'
            ]
        },
        {
            name: 'infyp-features',
            description: 'AI optimization features',
            entry: 'bundles/features.js',
            modules: [
                'modules/features/ai-optimizer.js',
                'modules/features/keyword-manager.js',
                'modules/features/usage-checker.js'
            ]
        },
        {
            name: 'infyp-settings',
            description: 'Settings management',
            entry: 'bundles/settings.js',
            modules: [
                'modules/settings.js',
                'modules/seo-config.js'
            ]
        }
    ],

    // Standalone files to minify (not bundle) - these are IIFE scripts
    standalone: [
        'setup-wizard.js',
        'attachment-page.js',
        'account-settings.js',
        'seo-score-page.js',
        'debug-page.js',
        'search-console.js',
        'dashboard.js',
        'api-usage-chart.js',
        'rest-api-chart.js',
        'redirect-settings.js'
    ],

    // Main entry point - imports all modules, will be bundled as single file
    mainEntry: 'admin-script.js'
};

// Check for dev flag
const isDev = process.argv.includes('--dev');

/**
 * Ensure directory exists
 */
function ensureDir(dir) {
    if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
    }
}

/**
 * Ensure bundles directory exists with entry files
 */
function ensureBundleEntries() {
    const bundlesDir = path.join(CONFIG.srcDir, 'bundles');
    ensureDir(bundlesDir);

    for (const bundle of CONFIG.bundles) {
        const entryPath = path.join(CONFIG.srcDir, bundle.entry);

        // Generate entry file content
        const imports = bundle.modules.map((mod, i) => {
            const modulePath = '../' + mod;
            const moduleName = path.basename(mod, '.js').replace(/-/g, '_');
            return `export { default as ${moduleName}_${i} } from '${modulePath}';`;
        }).join('\n');

        const content = `/**
 * ${bundle.name} Bundle Entry
 * ${bundle.description}
 *
 * Auto-generated by js-build.js - DO NOT EDIT
 */

${imports}
`;

        fs.writeFileSync(entryPath, content, 'utf8');
    }
}

/**
 * Get file size in KB
 */
function getFileSizeKB(filepath) {
    const stats = fs.statSync(filepath);
    return (stats.size / 1024).toFixed(2);
}

/**
 * Build a single bundle
 */
async function buildBundle(bundle, minify = true) {
    const entryPath = path.join(CONFIG.srcDir, bundle.entry);
    const outputName = bundle.name;
    const outputPath = path.join(CONFIG.distDir, `${outputName}.js`);
    const outputMinPath = path.join(CONFIG.distDir, `${outputName}.min.js`);

    try {
        // Build unminified version
        const rollupBundle = await rollup({
            input: entryPath,
            onwarn: (warning, warn) => {
                // Suppress circular dependency warnings for our modules
                if (warning.code === 'CIRCULAR_DEPENDENCY') return;
                warn(warning);
            }
        });

        await rollupBundle.write({
            file: outputPath,
            format: 'es',
            sourcemap: false,
            banner: `/* ${bundle.name} - ${bundle.description} */`
        });

        console.log(`  Created: ${outputName}.js (${getFileSizeKB(outputPath)} KB)`);

        // Build minified version
        if (minify) {
            const minBundle = await rollup({
                input: entryPath,
                plugins: [
                    terser({
                        compress: {
                            drop_console: true,
                            keep_fargs: true
                        },
                        mangle: {
                            reserved: ['infyp_vars', 'wp', 'jQuery', '$']
                        },
                        format: {
                            comments: false
                        }
                    })
                ],
                onwarn: (warning, warn) => {
                    if (warning.code === 'CIRCULAR_DEPENDENCY') return;
                    warn(warning);
                }
            });

            await minBundle.write({
                file: outputMinPath,
                format: 'es',
                sourcemap: false
            });

            console.log(`  Created: ${outputName}.min.js (${getFileSizeKB(outputMinPath)} KB)`);
        }

        await rollupBundle.close();

    } catch (error) {
        console.error(`  Error building ${bundle.name}:`, error.message);
        throw error;
    }
}

/**
 * Minify standalone files using terser
 */
async function minifyStandalone(filename) {
    const inputPath = path.join(CONFIG.srcDir, filename);
    const outputPath = path.join(CONFIG.distDir, filename.replace('.js', '.min.js'));

    if (!fs.existsSync(inputPath)) {
        console.warn(`  Warning: ${filename} not found`);
        return;
    }

    try {
        const bundle = await rollup({
            input: inputPath,
            plugins: [
                terser({
                    compress: {
                        drop_console: true,
                        keep_fargs: true
                    },
                    mangle: {
                        reserved: ['infyp_vars', 'wp', 'jQuery', '$']
                    },
                    format: {
                        comments: false
                    }
                })
            ],
            onwarn: () => {} // Suppress warnings for standalone files
        });

        await bundle.write({
            file: outputPath,
            format: 'iife',
            sourcemap: false
        });

        console.log(`  Created: ${filename.replace('.js', '.min.js')} (${getFileSizeKB(outputPath)} KB)`);
        await bundle.close();

    } catch (error) {
        console.error(`  Error minifying ${filename}:`, error.message);
    }
}

/**
 * Copy standalone files to dist (unminified)
 */
function copyStandalone(filename) {
    const inputPath = path.join(CONFIG.srcDir, filename);
    const outputPath = path.join(CONFIG.distDir, filename);

    if (!fs.existsSync(inputPath)) {
        console.warn(`  Warning: ${filename} not found`);
        return;
    }

    fs.copyFileSync(inputPath, outputPath);
    console.log(`  Copied: ${filename} (${getFileSizeKB(outputPath)} KB)`);
}

/**
 * Build main entry point as a single bundled file
 * This bundles admin-script.js with ALL its module dependencies into one file
 */
async function buildMainEntry(minify = true) {
    const inputPath = path.join(CONFIG.srcDir, CONFIG.mainEntry);
    const outputName = 'infyp-admin';
    const outputPath = path.join(CONFIG.distDir, `${outputName}.js`);
    const outputMinPath = path.join(CONFIG.distDir, `${outputName}.min.js`);

    if (!fs.existsSync(inputPath)) {
        console.warn(`  Warning: ${CONFIG.mainEntry} not found`);
        return;
    }

    try {
        // Build unminified version with all dependencies bundled
        const rollupBundle = await rollup({
            input: inputPath,
            onwarn: (warning, warn) => {
                if (warning.code === 'CIRCULAR_DEPENDENCY') return;
                warn(warning);
            }
        });

        await rollupBundle.write({
            file: outputPath,
            format: 'iife',
            name: 'infypAdmin',
            globals: {
                'jquery': 'jQuery'
            },
            sourcemap: false,
            banner: '/* infyp-admin - Main admin bundle with all modules */'
        });

        console.log(`  Created: ${outputName}.js (${getFileSizeKB(outputPath)} KB)`);

        // Build minified version
        if (minify) {
            const minBundle = await rollup({
                input: inputPath,
                plugins: [
                    terser({
                        compress: {
                            drop_console: true,
                            keep_fargs: true
                        },
                        mangle: {
                            reserved: ['infyp_vars', 'wp', 'jQuery', '$', 'postboxes', 'pagenow']
                        },
                        format: {
                            comments: false
                        }
                    })
                ],
                onwarn: (warning, warn) => {
                    if (warning.code === 'CIRCULAR_DEPENDENCY') return;
                    warn(warning);
                }
            });

            await minBundle.write({
                file: outputMinPath,
                format: 'iife',
                name: 'infypAdmin',
                globals: {
                    'jquery': 'jQuery'
                },
                sourcemap: false
            });

            console.log(`  Created: ${outputName}.min.js (${getFileSizeKB(outputMinPath)} KB)`);
        }

        await rollupBundle.close();

    } catch (error) {
        console.error(`  Error building main entry:`, error.message);
        throw error;
    }
}

/**
 * Clean dist directory
 */
function cleanDist() {
    if (fs.existsSync(CONFIG.distDir)) {
        const files = fs.readdirSync(CONFIG.distDir);
        for (const file of files) {
            // Only remove .js files, keep other assets
            if (file.endsWith('.js')) {
                fs.unlinkSync(path.join(CONFIG.distDir, file));
            }
        }
    }
}

/**
 * Main build function
 */
async function build() {
    console.log('\n\u{1F528} JavaScript Build Script');
    console.log('========================\n');

    const startTime = Date.now();

    // Clean and ensure dist directory exists
    ensureDir(CONFIG.distDir);
    cleanDist();

    // Build main admin bundle (all modules in one file)
    console.log('Building main admin bundle...');
    await buildMainEntry(!isDev);
    console.log();

    // Process standalone files (IIFE scripts for specific pages)
    // Always copy unminified versions for WordPress.org compliance (human-readable code required)
    console.log('Copying standalone scripts (unminified)...');
    for (const file of CONFIG.standalone) {
        copyStandalone(file);
    }

    // Also create minified versions in production mode
    if (!isDev) {
        console.log('\nMinifying standalone scripts...');
        for (const file of CONFIG.standalone) {
            await minifyStandalone(file);
        }
    }

    const elapsed = ((Date.now() - startTime) / 1000).toFixed(2);
    console.log(`\n\u2705 Build complete in ${elapsed}s\n`);

    // Summary
    if (!isDev) {
        const mainPath = path.join(CONFIG.distDir, 'infyp-admin.js');
        const mainMinPath = path.join(CONFIG.distDir, 'infyp-admin.min.js');

        if (fs.existsSync(mainPath) && fs.existsSync(mainMinPath)) {
            const origSize = fs.statSync(mainPath).size;
            const minSize = fs.statSync(mainMinPath).size;
            const savings = ((1 - minSize / origSize) * 100).toFixed(1);
            console.log(`\u{1F4CA} Main bundle minification savings: ${savings}%`);
        }
    }
}

// Run build
build().catch(error => {
    console.error('\u274C Build failed:', error.message);
    process.exit(1);
});
