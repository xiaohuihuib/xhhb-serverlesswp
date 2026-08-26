const fs = require('fs');
const path = require('path');
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

const requestRouter = '/tmp/serverlesswp-router.php';

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

// PHP's built-in server, when asked for a missing file under /wp-content/uploads/,
// walks up the tree and executes wp-content/index.php (empty body, 200). A router
// script keeps uploads safe and returns a clean 404 for missing files. Generated
// under /tmp so no file has to be placed inside the read-only wp/ bundle.
const routerPhp = `<?php
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$lowerUri = strtolower($uri);
$uploadsPrefix = '/wp-content/uploads/';

if (strpos($lowerUri, $uploadsPrefix) === 0) {
    if (substr($lowerUri, -1) === '/') {
        http_response_code(404);
        header('Cache-Control: no-store');
        echo 'Not Found';
        return true;
    }

    if (preg_match('/\\.(php|sql|sqlite3?|db|log|env|ini)$/i', $uri)) {
        http_response_code(404);
        header('Cache-Control: no-store');
        echo 'Not Found';
        return true;
    }

    $file = $_SERVER['DOCUMENT_ROOT'] . $uri;
    if (is_file($file)) {
        return false;
    }

    http_response_code(404);
    header('Cache-Control: no-store');
    echo 'Not Found';
    return true;
}

return false;
`;

if (!streamWrapperActive && !fs.existsSync(requestRouter)) {
    try {
        fs.writeFileSync(requestRouter, routerPhp);
    } catch (e) {
        console.log('Could not write upload router script:', e);
    }
}

function requestPath(event) {
    // Prefer explicit path fields; fall back to event.url for Vercel-style events.
    let url =
        event.rawPath ||
        event.path ||
        event.requestContext?.http?.path ||
        event.requestContext?.path ||
        event.url ||
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

const UPLOADS_MIME = {
    '.txt': 'text/plain',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.png': 'image/png',
    '.gif': 'image/gif',
    '.svg': 'image/svg+xml',
    '.webp': 'image/webp',
    '.ico': 'image/x-icon',
    '.pdf': 'application/pdf',
    '.mp4': 'video/mp4',
    '.mp3': 'audio/mpeg',
    '.css': 'text/css',
    '.js': 'application/javascript',
    '.xml': 'application/xml',
    '.json': 'application/json',
};

// Serve upload files directly from the local filesystem when the stream wrapper
// is not active. This mirrors how a real web server serves /wp-content/uploads/
// and avoids PHP's built-in server falling back to wp-content/index.php when a
// file is missing or unreadable.
function serveUploadStatic(urlPath) {
    if (streamWrapperActive) return null;

    const lower = urlPath.toLowerCase();
    if (!lower.startsWith('/wp-content/uploads/')) return null;
    if (lower.endsWith('/')) return null;
    if (isSensitiveUpload(urlPath)) return null;

    const filePath = path.resolve(pathToWP, '.' + urlPath);
    const exists = fs.existsSync(filePath);
    const isFile = exists && fs.statSync(filePath).isFile();
    if (!filePath.startsWith(pathToWP + path.sep) || !exists || !isFile) {
        return null;
    }

    const ext = path.extname(urlPath).toLowerCase();
    const contentType = UPLOADS_MIME[ext] || 'application/octet-stream';
    const data = fs.readFileSync(filePath);
    const isText = contentType.startsWith('text/')
        || contentType === 'application/javascript'
        || contentType === 'application/xml'
        || contentType === 'application/json';

    return {
        statusCode: 200,
        headers: {
            'content-type': contentType,
            'cache-control': 'public, max-age=31536000, immutable',
        },
        body: isText ? data.toString('utf8') : data.toString('base64'),
        isBase64Encoded: !isText,
    };
}

exports.handler = async function (event, context) {
    const urlPath = requestPath(event);
    if (isSensitiveUpload(urlPath)) {
        return {
            statusCode: 404,
            headers: { 'cache-control': 'no-store', 'content-type': 'text/plain' },
            body: 'Not Found',
        };
    }

    const directUpload = serveUploadStatic(urlPath);
    if (directUpload) {
        return directUpload;
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
