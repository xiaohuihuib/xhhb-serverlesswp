const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    globalSetup: './global-setup.js',
    workers: 1,
    use: {
        baseURL: 'https://localhost:3000',
        ignoreHTTPSErrors: true,
        storageState: 'auth.json',
    },
    timeout: 300000,
    navigationTimeout: 90000,
});
