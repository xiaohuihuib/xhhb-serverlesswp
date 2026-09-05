// Installs the fixed list of bundled plugins from wordpress.org. Any plugin
// directory already in wp-content/plugins/ that is not in the list is removed,
// so the result matches the original bash update script.

const fs = require('fs');
const path = require('path');
const os = require('os');

const api = require('./api.js');
const files = require('./files.js');

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

exports.report = function (installed) {
    const lines = ['Updates bundled plugins.', ''];
    for (const plugin of installed) {
        lines.push(`- **${plugin.slug}** ${plugin.version}`);
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
