const fs = require('fs');
const serverlesswp = require('serverlesswp');

const { validate } = require('../util/install.js');
const { setup } = require('../util/directory.js');
const storage = require('../util/storage.js');
const sandbox = require('../util/sandbox.js');
const readOnly = require('../util/readOnly.js');

const pathToWP = '/tmp/wp';
const wpContentPath = pathToWP + '/wp-content';
const sqlitePluginPath = wpContentPath + '/plugins/sqlite-database-integration';

const database = storage.resolve();

// Load executable bootstrap only from the read-only bundle.
const streamWrapperPrepend = '/var/task/wp/wp-content/mu-plugins/serverlesswp-stream-wrapper/bootstrap/prepend.php';

const requestRouter = '/var/task/wp/router.php';

const streamWrapperActive = !!process.env['SERVERLESSWP_STREAM_PROVIDER']
    && fs.existsSync(streamWrapperPrepend);

if (streamWrapperActive && !process.env['SERVERLESSWP_STREAM_WP_CONTENT_DIR']) {
    process.env['SERVERLESSWP_STREAM_WP_CONTENT_DIR'] = wpContentPath;
}

// Refuse to fall back to ephemeral writes.
if (streamWrapperActive && typeof serverlesswp.buildPhpArgs !== 'function') {
    console.log('SERVERLESSWP_STREAM_PROVIDER is set but the installed serverlesswp package does not support autoPrependFile. Upgrade it, or wp-content writes will not reach object storage.');
}

const readOnlyActive = !!process.env['SERVERLESSWP_READ_ONLY_MODE']
    && !['false', '0', 'no'].includes(process.env['SERVERLESSWP_READ_ONLY_MODE'].toLowerCase());

let initDone = false;

setup();

function requestPath(event) {
    let url =
        event.url ||
        event.rawPath ||
        event.path ||
        event.requestContext?.http?.path ||
        event.requestContext?.path ||
        '/';
    // Some platforms pass the full URL; normalize to the path.
    if (typeof url === 'string' && url.startsWith('http')) {
        try {
            url = new URL(url).pathname;
        } catch (e) {
            // fall through to best-effort split below
        }
    }
    return url.split('?')[0];
}

const STATIC_EXTENSIONS = new Set([
    'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp',
    'woff', 'woff2', 'ttf', 'otf', 'eot', 'txt', 'xml', 'map',
]);

function setCacheHeaders(event, response) {
    if (!response?.headers) {
        return;
    }

    // Only cache successful GET responses.
    const method = (event.httpMethod || event.requestContext?.http?.method || 'GET').toUpperCase();
    if (method !== 'GET' || response.statusCode !== 200) {
        return;
    }

    const urlPath = requestPath(event).toLowerCase();
    // Never cache admin, login, installer, or REST API endpoints.
    if (
        urlPath.startsWith('/wp-admin') ||
        urlPath.startsWith('/wp-login.php') ||
        urlPath.startsWith('/installer.php') ||
        urlPath.startsWith('/wp-json/') ||
        urlPath.includes('/wp-json/')
    ) {
        return;
    }

    const requestCookies = event.headers?.cookie || event.headers?.Cookie || '';
    const setCookie = response.headers['set-cookie'] || response.headers['Set-Cookie'];
    if (requestCookies || setCookie) {
        // Personalized/logged-in responses should not be cached.
        return;
    }

    const contentType = response.headers['content-type'] || response.headers['Content-Type'] || '';
    const ext = urlPath.split('.').pop();

    if (STATIC_EXTENSIONS.has(ext)) {
        // Static assets can be cached for a year by browsers and CDNs.
        response.headers['cache-control'] = 'public, max-age=31536000, immutable';
    } else if (contentType.includes('text/html')) {
        // HTML pages: browser revalidates immediately, CDN caches for the configured duration.
        const maxAge = parseInt(process.env.SERVERLESSWP_CACHE_MAX_AGE || '3600', 10);
        response.headers['cache-control'] = `public, max-age=0, s-maxage=${maxAge}`;
    }
}

function isSensitiveUpload(urlPath) {
    // Defensive: some gateways URL-encode the path or pass the full URL.
    let decoded;
    try {
        decoded = decodeURIComponent(urlPath);
    } catch (e) {
        decoded = urlPath;
    }
    const lower = decoded.toLowerCase();
    if (!lower.startsWith('/wp-content/uploads/')) return false;
    if (lower.endsWith('/')) return true;
    return /\.(php|sql|sqlite3?|db|log|env|ini)$/i.test(decoded);
}

exports.handler = async function (event, context, callback) {
    const urlPath = requestPath(event);
    if (isSensitiveUpload(urlPath)) {
        return {
            statusCode: 404,
            headers: { 'cache-control': 'no-store', 'content-type': 'text/plain' },
            body: 'Not Found',
        };
    }

    if (!initDone) {
        // Block mutations before opening SQLite.
        if (readOnlyActive) {
            serverlesswp.registerPlugin(readOnly);
        }
        if (database.plugin) {
            await database.plugin.prepPlugin(wpContentPath, sqlitePluginPath);
            database.plugin.config(database.config);
            serverlesswp.registerPlugin(database.plugin);
        }
        if (process.env['SERVERLESSWP_DATA_SECRET']) {
            serverlesswp.registerPlugin(sandbox);
        }
        initDone = true;
    }

    const options = { docRoot: pathToWP, event: event };
    if (fs.existsSync(requestRouter)) {
        options.routerScript = requestRouter;
    }
    if (streamWrapperActive) {
        options.autoPrependFile = streamWrapperPrepend;
    }

    const response = await serverlesswp(options);

    // Apply public cache headers unless read-only mode already handled them.
    if (!readOnlyActive) {
        setCacheHeaders(event, response);
    }

    const checkInstall = validate(response);
    return checkInstall || response;
};
