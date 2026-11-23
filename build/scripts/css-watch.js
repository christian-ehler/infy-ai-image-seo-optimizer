#!/usr/bin/env node

/**
 * CSS Watch Script
 *
 * Watches CSS files for changes and triggers rebuild.
 * Uses chokidar for efficient file system watching.
 *
 * Usage:
 *   node scripts/css-watch.js
 *   npm run watch:css
 */

const chokidar = require('chokidar');
const { exec } = require('child_process');
const path = require('path');

// Configuration
const WATCH_PATHS = [
    'assets/css/**/*.css',
    '!assets/css/dist/**'
];

const DEBOUNCE_MS = 300;

let buildTimeout = null;
let isBuilding = false;

/**
 * Run the CSS build
 */
function runBuild() {
    if (isBuilding) {
        return;
    }

    isBuilding = true;
    const startTime = Date.now();

    console.log('\n🔄 Rebuilding CSS...');

    exec('node scripts/css-build.js', (error, stdout, stderr) => {
        isBuilding = false;

        if (error) {
            console.error('❌ Build failed:', error.message);
            if (stderr) console.error(stderr);
            return;
        }

        const duration = Date.now() - startTime;
        console.log(stdout);
        console.log(`⏱️  Build completed in ${duration}ms`);
        console.log('\n👀 Watching for changes...');
    });
}

/**
 * Debounced build trigger
 */
function triggerBuild(filepath) {
    const filename = path.basename(filepath);
    console.log(`📝 Changed: ${filename}`);

    if (buildTimeout) {
        clearTimeout(buildTimeout);
    }

    buildTimeout = setTimeout(runBuild, DEBOUNCE_MS);
}

/**
 * Main watch function
 */
function watch() {
    console.log('\n👀 CSS Watch Script');
    console.log('==================\n');
    console.log('Watching for CSS changes...\n');

    const watcher = chokidar.watch(WATCH_PATHS, {
        ignored: /node_modules|\.git|dist/,
        persistent: true,
        ignoreInitial: true
    });

    watcher
        .on('change', triggerBuild)
        .on('add', triggerBuild)
        .on('unlink', triggerBuild)
        .on('error', error => console.error('Watch error:', error));

    // Initial build
    runBuild();

    // Graceful shutdown
    process.on('SIGINT', () => {
        console.log('\n\n👋 Stopping watch...');
        watcher.close();
        process.exit(0);
    });
}

// Run watch
watch();
