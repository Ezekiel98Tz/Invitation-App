import { defineConfig, devices } from '@playwright/test';
import { browserBaseUrl, browserTestEnvironment } from './tests/e2e/support/environment';

export default defineConfig({
    testDir: './tests/e2e',
    globalSetup: './tests/e2e/global-setup.ts',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 30_000,
    expect: { timeout: 7_500 },
    outputDir: './test-results',
    reporter: [
        ['list'],
        ['html', { outputFolder: './playwright-report', open: 'never' }],
    ],
    use: {
        baseURL: browserBaseUrl,
        trace: 'off',
        screenshot: 'off',
        video: 'off',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        command: 'node scripts/serve-browser-tests.mjs',
        url: `${browserBaseUrl}/up`,
        reuseExistingServer: false,
        timeout: 120_000,
        env: browserTestEnvironment,
    },
});
