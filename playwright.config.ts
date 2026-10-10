import { defineConfig, devices } from '@playwright/test';

const baseURL = 'http://localhost:8123';

/**
 * Smoke flows against `php artisan serve` with `.env.e2e`. The specs share one database, so they run
 * one at a time; `tests/e2e/seed.php` resets it before the run and again before each spec file.
 */
export default defineConfig({
    testDir: 'tests/e2e',
    globalSetup: './tests/e2e/global-setup.ts',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'desktop',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 820 } },
        },
        {
            // Tablet width beside the sidebar: only the layout checks and the Finnish run (whose longer text
            // must fit too); the other flows run at desktop and phone.
            name: 'tablet',
            testMatch: ['layout.spec.ts', 'finnish.spec.ts'],
            use: { ...devices['Desktop Chrome'], viewport: { width: 820, height: 1180 } },
        },
        {
            name: 'phone',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 390, height: 820 },
                isMobile: true,
                hasTouch: true,
            },
        },
    ],
    webServer: {
        command: 'APP_ENV=e2e php artisan serve --host=localhost --port=8123',
        url: `${baseURL}/up`,
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
    },
});
