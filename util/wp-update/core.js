// Updates WordPress by downloading the full Chinese localized release,
// overlaying it onto wp/, and restoring the repository's custom files.
// This matches the behaviour of the original bash update script.

const fs = require('fs');
const path = require('path');
const os = require('os');

const api = require('./api.js');
const files = require('./files.js');

// Files that must survive every core update.
const PRESERVED_FILES = [
    'wp-config.php',
    'wordfence-waf.php',
    'wp-content/mu-plugins/serverlesswp.php',
    'wp-content/mu-plugins/serverlesswp-stream-wrapper.php',
    'wp-content/languages/plugins/asgaros-forum-zh_CN.po',
    'wp-content/languages/plugins/asgaros-forum-zh_CN.mo',
    'wp-content/languages/plugins/asgaros-forum-zh_CN.l10n.php',
    'wp-content/languages/plugins/wordpress-importer-zh_CN.po',
    'wp-content/languages/plugins/wordpress-importer-zh_CN.mo',
    'wp-content/languages/plugins/wordpress-importer-zh_CN.l10n.php',
    'wp-content/languages/plugins/wordfence-zh_CN.po',
    'wp-content/languages/plugins/wordfence-zh_CN.mo',
    'wp-content/languages/plugins/wordfence-zh_CN.l10n.php',
    'wp-content/languages/plugins/wordfence-zh_CN-0d455069dd479112c75ad60d5dfe35da.json',
    'wp-content/languages/plugins/wordfence-zh_CN-3a5c971b121f299b74a6c03ec10dfde1.json',
    'wp-content/languages/plugins/wordfence-zh_CN-9f22b9f504df7b65b96763ff03cb4cde.json',
    'wp-content/languages/plugins/wordfence-zh_CN-60cecc84730292c14de6fa6a684fa5a2.json',
    'wp-content/languages/plugins/wordfence-zh_CN-925338e6c068b12411f2a3e130f029b4.json',
];

// Directories that must survive every core update.
const PRESERVED_DIRS = [
    'wp-content/mu-plugins/serverlesswp-stream-wrapper',
];

// Default themes shipped with WordPress that the bash script removes.
const BUNDLED_THEMES_TO_REMOVE = [
    'twentytwenty',
    'twentytwentyone',
    'twentytwentytwo',
    'twentytwentythree',
    'twentytwentyfour',
    'twentytwentyfive',
];

function backupPreserved(root, backupDir) {
    fs.mkdirSync(backupDir, { recursive: true });

    for (const filePath of PRESERVED_FILES) {
        const src = path.join(root, filePath);
        const dest = path.join(backupDir, filePath);
        if (fs.existsSync(src)) {
            fs.mkdirSync(path.dirname(dest), { recursive: true });
            fs.copyFileSync(src, dest);
        }
    }

    for (const dirPath of PRESERVED_DIRS) {
        const src = path.join(root, dirPath);
        const dest = path.join(backupDir, dirPath);
        if (fs.existsSync(src)) {
            files.copyRecursive(src, dest);
        }
    }
}

function restorePreserved(backupDir, root) {
    for (const filePath of PRESERVED_FILES) {
        const src = path.join(backupDir, filePath);
        const dest = path.join(root, filePath);
        if (fs.existsSync(src)) {
            fs.mkdirSync(path.dirname(dest), { recursive: true });
            fs.copyFileSync(src, dest);
        }
    }

    for (const dirPath of PRESERVED_DIRS) {
        const src = path.join(backupDir, dirPath);
        const dest = path.join(root, dirPath);
        if (fs.existsSync(src)) {
            files.copyRecursive(src, dest);
        }
    }
}

// Copies every file from sourceRoot into root, overwriting what is there.
function overlay(sourceRoot, root) {
    for (const entry of fs.readdirSync(sourceRoot)) {
        files.copyRecursive(path.join(sourceRoot, entry), path.join(root, entry));
    }
}

// The version WordPress reports for itself.
exports.installedVersion = function (wpRoot) {
    const versionFile = path.join(wpRoot, 'wp-includes', 'version.php');
    const match = /\$wp_version\s*=\s*'([^']+)'/.exec(fs.readFileSync(versionFile, 'utf8'));

    if (!match) {
        throw new Error(`could not read a version from ${versionFile}`);
    }

    return match[1];
};

exports.run = async function (options) {
    const from = exports.installedVersion(options.root);
    const requested = options.target || 'latest';

    if (options.dryRun) {
        const report = [
            `Updates the bundled WordPress files from ${from} to the latest Chinese release.`,
            '',
            '- Overlay the full latest-zh_CN.zip release',
            `- Preserve ${PRESERVED_FILES.length} custom file(s) and ${PRESERVED_DIRS.length} custom directory(s)`,
            '- Remove bundled default themes and hello.php',
        ].join('\n');
        return { updated: false, report, outputs: { from, to: 'latest' } };
    }

    const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'wp-update-'));
    let to;
    try {
        const releaseRoot = await api.downloadChineseRelease(requested, workDir);
        to = exports.installedVersion(releaseRoot);
        const backupDir = path.join(workDir, 'preserved');

        backupPreserved(options.root, backupDir);
        overlay(releaseRoot, options.root);
        restorePreserved(backupDir, options.root);

        files.removeIfExists(path.join(options.root, 'wp-content', 'plugins', 'hello.php'));

        for (const theme of BUNDLED_THEMES_TO_REMOVE) {
            files.removeIfExists(path.join(options.root, 'wp-content', 'themes', theme));
        }

        files.removeIfExists(path.join(options.root, 'wp-content', 'languages', 'themes'));
    } finally {
        fs.rmSync(workDir, { recursive: true, force: true });
    }

    const reportLines = [
        `Updates the bundled WordPress files from ${from} to ${to} using the Chinese release.`,
        '',
        `- Overlay the full wordpress-${to}-zh_CN.zip release`,
        `- Preserve ${PRESERVED_FILES.length} custom file(s) and ${PRESERVED_DIRS.length} custom directory(s)`,
        '- Remove bundled default themes and hello.php',
    ];

    console.log(`Updated ${options.root} to WordPress ${to} (Chinese locale).`);
    return { updated: true, report: reportLines.join('\n'), outputs: { from, to } };
};
