// Database selection in util/storage.js.
//
// The Vercel Blob cases matter most: a store connected through the deploy
// button authenticates with OIDC, so BLOB_STORE_ID and a platform-minted
// VERCEL_OIDC_TOKEN are all the deployment gets. Requiring a read-write token
// there leaves the site on the setup page with a working store attached.

const test = require('node:test');
const assert = require('node:assert');

const storage = require('../util/storage.js');

// Only keep environment variables relevant to MySQL and none modes.
const MANAGED = [
    'DATABASE', 'USERNAME', 'PASSWORD', 'HOST',
];

let saved;

test.beforeEach(() => {
    saved = {};
    for (const name of MANAGED) {
        saved[name] = process.env[name];
        delete process.env[name];
    }
});

test.afterEach(() => {
    for (const name of MANAGED) {
        if (saved[name] === undefined) {
            delete process.env[name];
        } else {
            process.env[name] = saved[name];
        }
    }
});

test('no credentials means the setup page', () => {
    assert.strictEqual(storage.resolve().mode, 'none');
});

test('MySQL wins over a connected blob store', () => {
    process.env.DATABASE = 'wordpress';
    process.env.USERNAME = 'wp';
    process.env.PASSWORD = 'secret';
    process.env.HOST = 'db.example.com';

    assert.strictEqual(storage.resolve().mode, 'mysql');
});
