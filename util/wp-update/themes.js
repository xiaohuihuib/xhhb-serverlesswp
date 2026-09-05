// Installs the argon theme from the repository's GitHub release and removes
// every other theme directory, so the result matches the original bash update
// script.

const fs = require('fs');
const path = require('path');
const os = require('os');

const api = require('./api.js');
const files = require('./files.js');

const ARGON_URL = 'https://github.com/xiaohuihuib/xhhb-serverlesswp/releases/download/V1.3.5/argon.zip';
const ARGON_SLUG = 'argon';

exports.report = function () {
    return [
        '## Themes',
        '',
        `- Installed \`${ARGON_SLUG}\` from the GitHub release asset`,
        '- Removed all other theme directories',
    ].join('\n');
};

exports.run = async function (options) {
    const themesRoot = path.join(options.root, 'wp-content', 'themes');

    console.log(`Installing ${ARGON_SLUG} theme into ${themesRoot}...`);

    if (options.dryRun) {
        return { updated: false, report: exports.report(), outputs: { themes: 1 } };
    }

    // Remove every theme directory currently present.
    let entries = [];
    try {
        entries = fs.readdirSync(themesRoot, { withFileTypes: true });
    } catch {
        // directory may not exist yet
    }
    for (const entry of entries) {
        const fullPath = path.join(themesRoot, entry.name);
        if (entry.isFile() && entry.name === 'index.php') {
            continue;
        }
        files.removeIfExists(fullPath);
    }

    const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'wp-themes-'));
    try {
        const source = await api.downloadArchive(ARGON_URL, workDir, ARGON_SLUG);
        files.copyRecursive(source, path.join(themesRoot, ARGON_SLUG));
        console.log(`Installed ${ARGON_SLUG} theme.`);
    } finally {
        fs.rmSync(workDir, { recursive: true, force: true });
    }

    return { updated: true, report: exports.report(), outputs: { themes: 1 } };
};
