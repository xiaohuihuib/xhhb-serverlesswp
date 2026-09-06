// Installs the argon theme from the repository's GitHub release and reports on
// the state of themes already present in the working copy.

const fs = require('fs');
const path = require('path');
const os = require('os');

const api = require('./api.js');
const files = require('./files.js');
const versions = require('./versions.js');

const ARGON_URL = 'https://github.com/xiaohuihuib/xhhb-serverlesswp/releases/download/V1.3.5/argon.zip';
const ARGON_SLUG = 'argon';

// Reads a theme's header from its style.css. Returns { version } or null when
// no Theme Name is found.
exports.readHeader = function (dir) {
    const style = path.join(dir, 'style.css');
    let text;
    try {
        text = fs.readFileSync(style, 'utf8');
    } catch {
        return null;
    }

    const name = versions.headerField(text, 'Theme Name');
    if (!name) {
        return null;
    }

    return {
        version: versions.headerField(text, 'Version'),
    };
};

// Finds every directory under root that contains a valid theme header.
exports.discover = function (root) {
    let entries;
    try {
        entries = fs.readdirSync(root, { withFileTypes: true });
    } catch {
        return [];
    }

    const found = [];
    for (const entry of entries) {
        if (!entry.isDirectory()) {
            continue;
        }

        const dir = path.join(root, entry.name);
        const header = exports.readHeader(dir);
        if (!header) {
            continue;
        }

        found.push({
            slug: entry.name,
            dir,
            installed: header.version,
        });
    }

    return found;
};

// Extracts the slugs of themes that ship with WordPress from a core checksum map.
exports.bundledSlugs = function (checksums) {
    const slugs = new Set();
    const pattern = /^wp-content\/themes\/([^/]+)\/style\.css$/;
    for (const filePath of Object.keys(checksums)) {
        const match = pattern.exec(filePath);
        if (match) {
            slugs.add(match[1]);
        }
    }
    return slugs;
};

// Compares a theme to its wordpress.org listing. Themes are never overwritten
// because .org publishes no checksums, but they are reported.
exports.inspect = async function ({ slug, installed }, bundled) {
    if (bundled.has(slug)) {
        return { slug, status: 'core' };
    }

    const info = await api.themeInfo(slug);
    if (!info) {
        return { slug, status: 'not-on-org' };
    }

    if (installed === undefined) {
        return { slug, status: 'no-version' };
    }

    if (versions.compareVersions(installed, info.version) < 0) {
        return { slug, status: 'outdated', latest: info.version };
    }

    return { slug, status: 'current' };
};

function installReport() {
    return [
        '## Themes',
        '',
        `- Installed \`${ARGON_SLUG}\` from the GitHub release asset`,
        '- Removed all other theme directories',
    ].join('\n');
}

// Pull request body / summary for theme reporting.
exports.report = function (themes) {
    if (!Array.isArray(themes)) {
        return installReport();
    }

    const lines = ['## Themes', ''];
    const outdated = themes.filter((t) => t.status === 'outdated');
    const core = themes.filter((t) => t.status === 'core');
    const notOnOrg = themes.filter((t) => t.status === 'not-on-org');

    if (outdated.length) {
        lines.push(`${outdated.length} theme(s) have a newer release`);
        for (const theme of outdated) {
            lines.push(
                `- \`${theme.slug}\` ${theme.installed} → ${theme.latest} ` +
                `([wordpress.org/themes/${theme.slug}/](https://wordpress.org/themes/${theme.slug}/))`,
            );
        }
    } else {
        lines.push('No theme has a newer release.');
    }

    if (core.length) {
        lines.push('', `${core.length} shipped with WordPress`);
        for (const theme of core) {
            lines.push(`- \`${theme.slug}\``);
        }
    }

    if (notOnOrg.length) {
        lines.push('');
        for (const theme of notOnOrg) {
            lines.push(`- \`${theme.slug}\` is not published on wordpress.org`);
        }
    }

    return lines.join('\n');
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
