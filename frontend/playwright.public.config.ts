import { defineConfig } from '@playwright/test'

export default defineConfig({
    testDir: './e2e-public',
    timeout: 120_000,
    expect: { timeout: 10_000 },
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: process.env.PUBLIC_BASE_URL ?? 'http://127.0.0.1:8000',
        channel: 'chromium',
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'mobile-390',
            use: {
                browserName: 'chromium',
                viewport: { width: 390, height: 844 },
                deviceScaleFactor: 3,
                isMobile: true,
                hasTouch: true,
            },
        },
        {
            name: 'tablet-768',
            use: {
                browserName: 'chromium',
                viewport: { width: 768, height: 1024 },
                deviceScaleFactor: 2,
                isMobile: true,
                hasTouch: true,
            },
        },
        {
            name: 'desktop-1440',
            use: {
                browserName: 'chromium',
                viewport: { width: 1440, height: 900 },
            },
        },
    ],
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8000',
        cwd: '../backend',
        url: 'http://127.0.0.1:8000/robots.txt',
        reuseExistingServer: true,
        timeout: 90_000,
    },
})
