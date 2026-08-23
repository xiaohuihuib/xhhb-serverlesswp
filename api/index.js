const fs = require('fs');
const serverlesswp = require('serverlesswp');

const { validate } = require('../util/install.js');
const { setup } = require('../util/directory.js');
const sandbox = require('../util/sandbox.js');
const readOnly = require('../util/readOnly.js');

const pathToWP = '/tmp/wp';
const wpContentPath = pathToWP + '/wp-content';

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

function sanitizeHeaders(headers) {
    if (!headers) return {};
    const safe = {};
    for (const [key, value] of Object.entries(headers)) {
        if (typeof value === 'string' && /^[\x00-\x7F]*$/.test(value)) {
            safe[key] = value;
        }
    }
    return safe;
}
// ------------------------------------------------------------

exports.handler = async function (event, context, callback) {
    const cleanEvent = { ...event, headers: sanitizeHeaders(event.headers) };

    if (!initDone) {
        // Block mutations before opening SQLite.
        if (readOnlyActive) {
            serverlesswp.registerPlugin(readOnly);
        }
        if (process.env['SERVERLESSWP_DATA_SECRET']) {
            serverlesswp.registerPlugin(sandbox);
        }
        initDone = true;
    }

    const options = { docRoot: pathToWP, event: cleanEvent };
    if (fs.existsSync(requestRouter)) {
        options.routerScript = requestRouter;
    }
    if (streamWrapperActive) {
        options.autoPrependFile = streamWrapperPrepend;
    }

    const response = await serverlesswp(options);
    const checkInstall = validate(response);
    return checkInstall || response;
};
