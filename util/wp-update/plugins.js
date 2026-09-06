// Installs the fixed list of bundled plugins from wordpress.org and reports on
// plugins that are tracked from GitHub or already present in the working copy.
// Any plugin directory already in wp-content/plugins/ that is not in the bundled
// list is removed, so the result matches the original bash update script.

const fs = require('fs');
const path = require('path');
const os = require('os');

const api = require('./api.js');
const files = require('./files.js');
const github = require('./github.js');
const versions = require('./versions.js');

// Plugins to install and the version to request. 'latest' uses the unversioned
// wordpress.org URL, which redirects to the newest release.
const PLUGINS = [
    { slug: 'tidb-compatibility', version: 'latest' },
    { slug: 'simple-cloudflare-turnstile', version: 'latest' },
    { slug: 'yctvn-media-offload-cloudflare-r2', version: 'latest' },
    { slug: 'asgaros-forum', version: '3.4.0' },
    { slug: 'integrate-umami', version: 'latest' },
    { slug: 'wordpress-importer', version: '0.9.6' },
    { slug: 'default-admin-color-scheme', version: 'latest' },
    { slug: 'ai', version: 'latest' },
    { slug: 'ai-provider-for-cloudflare', version: 'latest' },
    { slug: 'wordfence', version: '9.0.0' },
    { slug: 'akismet', version: 'latest' },
];

// Plugins that follow a GitHub repository branch instead of wordpress.org.
exports.TRACKED = {
    'sqlite-database-integration': {
        repo: 'WordPress/sqlite-database-integration',
        path: 'packages/plugin-sqlite-database-integration',
    },
};

exports.compareVersions = versions.compareVersions;

// wordpress.org publishes an array of accepted md5s for a re-tagged release.
// Resolve each path to the hash that matches the local copy, falling back to
// the first accepted hash when the local copy is different or absent.
exports.acceptedHashes = function (sums, disk) {
    const resolved = {};
    for (const filePath of Object.keys(sums)) {
        const accepted = sums[filePath];
        if (Array.isArray(accepted)) {
            resolved[filePath] = accepted.includes(disk[filePath]) ? disk[filePath] : accepted[0];
        } else {
            resolved[filePath] = accepted;
        }
    }
    return resolved;
};

// Every file path under dir, relative to dir. Missing directories are empty.
exports.filesUnder = function (dir) {
    if (!fs.existsSync(dir)) {
        return [];
    }

    const results = [];

    function walk(current, prefix) {
        for (const entry of fs.readdirSync(current, { withFileTypes: true })) {
            const relative = prefix ? `${prefix}/${entry.name}` : entry.name;
            if (entry.isDirectory()) {
                walk(path.join(current, entry.name), relative);
            } else if (entry.isFile()) {
                results.push(relative);
            }
        }
    }

    walk(dir, '');
    return results;
};

// Reads a plugin's header from the first top-level .php file that names it.
// Returns { mainFile, version } or null when no plugin header is found.
exports.readHeader = function (dir) {
    let entries;
    try {
        entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch {
        return null;
    }

    for (const entry of entries) {
        if (!entry.isFile() || !entry.name.endsWith('.php')) {
            continue;
        }

        const file = path.join(dir, entry.name);
        const text = fs.readFileSync(file, 'utf8');
        const name = versions.headerField(text, 'Plugin Name');
        if (!name) {
            continue;
        }

        return {
            mainFile: entry.name,
            version: versions.headerField(text, 'Version'),
        };
    }

    return null;
};

// Finds every directory under root that contains a plugin header.
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

// Compares a plugin on disk to its wordpress.org listing. Returns a status and,
// when an update is safe, a plan of files to write.
exports.inspect = async function ({ slug, dir, installed }) {
    if (installed === undefined) {
        return { slug, status: 'no-version' };
    }

    const tracked = exports.TRACKED[slug];
    if (tracked) {
        return exports.inspectTracked({ slug, dir }, tracked);
    }

    const info = await api.pluginInfo(slug);
    if (!info) {
        return { slug, status: 'not-on-org' };
    }

    const comparison = versions.compareVersions(installed, info.version);
    if (comparison > 0) {
        return { slug, status: 'ahead' };
    }
    if (comparison === 0) {
        return { slug, status: 'current' };
    }

    const oldSums = await api.pluginChecksums(slug, installed);
    const newSums = await api.pluginChecksums(slug, info.version);
    if (!oldSums || !newSums) {
        return { slug, status: 'unverifiable' };
    }

    const paths = Array.from(new Set([...Object.keys(oldSums), ...Object.keys(newSums)]));
    const disk = files.hashDisk(dir, paths);
    const oldResolved = exports.acceptedHashes(oldSums, disk);

    for (const filePath of paths) {
        if (oldResolved[filePath] !== disk[filePath]) {
            return { slug, status: 'modified' };
        }
    }

    const newResolved = exports.acceptedHashes(newSums, disk);
    const writes = [];
    const deletes = [];

    for (const filePath of paths) {
        const after = newResolved[filePath];
        if (after !== undefined && after !== disk[filePath]) {
            writes.push(filePath);
        } else if (after === undefined && disk[filePath] !== undefined) {
            deletes.push(filePath);
        }
    }

    return {
        slug,
        status: 'update',
        installed,
        latest: info.version,
        source: 'wordpress.org',
        plan: { writes, deletes },
    };
};

// Compares a plugin on disk to the current state of a GitHub branch.
exports.inspectTracked = async function ({ slug, dir }, { repo, path: subdir }) {
    const branch = await github.defaultBranch(repo);
    const tree = await github.effectiveTree(repo, branch, subdir);
    const localFiles = exports.filesUnder(dir);

    const disk = {};
    for (const filePath of localFiles) {
        disk[filePath] = github.blobSha(fs.readFileSync(path.join(dir, filePath)));
    }

    const writes = [];
    for (const [filePath, sha] of tree) {
        if (disk[filePath] !== sha) {
            writes.push(filePath);
        }
    }

    const deletes = [];
    for (const filePath of localFiles) {
        if (!tree.has(filePath)) {
            deletes.push(filePath);
        }
    }

    return {
        slug,
        status: writes.length || deletes.length ? 'track' : 'current',
        source: `${repo}@${branch}`,
        plan: { writes, deletes },
    };
};

function reportLegacy(installed) {
    const lines = ['Updates bundled plugins.', ''];
    for (const plugin of installed) {
        lines.push(`- **${plugin.slug}** ${plugin.version}`);
    }
    return lines.join('\n');
}

// Pull request body for plugin updates.
exports.report = function (installed) {
    if (!Array.isArray(installed) || (installed.length > 0 && installed[0].status === undefined)) {
        return reportLegacy(installed);
    }

    const lines = ['Updates bundled plugins.', ''];

    for (const plugin of installed) {
        let line = `- **${plugin.slug}**`;

        if (plugin.status === 'update') {
            line += ` ${plugin.installed} → ${plugin.latest}`;
        } else if (plugin.installed !== undefined) {
            line += ` ${plugin.installed}`;
        }

        if (plugin.status === 'track') {
            line += ` follows \`${plugin.source}\``;
            lines.push(line);
            if (plugin.plan) {
                lines.push(`  - ${plugin.plan.writes.length} file(s) changed, ${plugin.plan.deletes.length} removed`);
                lines.push('  - no checksums to check them against');
            }
            continue;
        }

        if (plugin.status === 'ahead') {
            line += ' (ahead of wordpress.org)';
        } else if (plugin.status === 'not-on-org') {
            line += ' (not published on wordpress.org)';
        } else if (plugin.status === 'current') {
            line += ' (up to date)';
        }

        lines.push(line);
    }

    const untouched = installed.filter(
        (p) => p.status === 'ahead' || p.status === 'not-on-org' || p.status === 'modified' || p.status === 'unverifiable',
    ).length;
    const upToDate = installed.filter((p) => p.status === 'current').length;

    if (untouched) {
        lines.push('', `${untouched} plugin(s) left untouched`);
    }
    if (upToDate) {
        lines.push('', `${upToDate} plugin(s) already up to date`);
    }

    return lines.join('\n');
};

exports.run = async function (options) {
    const pluginsRoot = path.join(options.root, 'wp-content', 'plugins');

    console.log(`Installing ${PLUGINS.length} bundled plugin(s) into ${pluginsRoot}...`);

    if (options.dryRun) {
        return { updated: false, report: exports.report(PLUGINS), outputs: { plugins: PLUGINS.length } };
    }

    // Remove every plugin directory currently present, leaving only the stock
    // index.php that WordPress ships.
    let entries = [];
    try {
        entries = fs.readdirSync(pluginsRoot, { withFileTypes: true });
    } catch {
        // directory may not exist yet
    }
    for (const entry of entries) {
        const fullPath = path.join(pluginsRoot, entry.name);
        if (entry.isFile() && entry.name === 'index.php') {
            continue;
        }
        files.removeIfExists(fullPath);
    }

    const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'wp-plugins-'));
    const installed = [];
    try {
        for (const plugin of PLUGINS) {
            const source = plugin.version === 'latest'
                ? await api.downloadPluginLatest(plugin.slug, workDir)
                : await api.downloadPlugin(plugin.slug, plugin.version, workDir);
            const dest = path.join(pluginsRoot, plugin.slug);
            files.copyRecursive(source, dest);
            installed.push(plugin);
            console.log(`Installed ${plugin.slug} (${plugin.version}).`);
        }
    } finally {
        fs.rmSync(workDir, { recursive: true, force: true });
    }

    return { updated: true, report: exports.report(installed), outputs: { plugins: installed.length } };
};
