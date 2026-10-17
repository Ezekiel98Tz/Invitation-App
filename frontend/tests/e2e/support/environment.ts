import path from 'node:path';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const supportDir = path.dirname(fileURLToPath(import.meta.url));
export const projectRoot = path.resolve(supportDir, '../../../..');
export const frontendRoot = path.resolve(supportDir, '../..');

export const browserBaseUrl = 'http://127.0.0.1:8001';

const testUserPassword = process.env.BROWSER_TEST_USER_PASSWORD ?? randomBytes(24).toString('base64url');
if (testUserPassword.length < 12) {
    throw new Error('BROWSER_TEST_USER_PASSWORD must contain at least 12 characters.');
}
// Reuse this run-scoped secret in Playwright global setup, workers, and the
// guarded Laravel web server without writing it to a fixture or report.
process.env.BROWSER_TEST_USER_PASSWORD = testUserPassword;

export const testUser = {
    name: 'Browser Test Organizer',
    email: 'browser.owner@example.test',
    password: testUserPassword,
};

export const emptyTestUser = {
    name: 'Empty Workspace Tester',
    email: 'browser.empty@example.test',
    password: testUserPassword,
};

export const staffTestUser = {
    name: 'Browser Test Staff',
    email: 'browser.staff@example.test',
    password: testUserPassword,
};

export const testEventTitle = 'Browser Test Event';
export const testStandaloneEventTitle = 'Browser Test Standalone Event';
export const testCelebrationName = 'Browser Test Celebration';
export const testPastEventTitle = 'Browser Test Past Event';
export const testUpcomingEventTitle = 'Browser Test Upcoming Event';

/**
 * Explicit environment for the Playwright-managed Laravel server. Database
 * credentials and the application key still come from the existing untracked
 * .env file; service credentials and database URLs are deliberately cleared.
 */
export const browserTestEnvironment: Record<string, string> = {
    APP_ENV: 'testing',
    APP_DEBUG: 'true',
    APP_URL: browserBaseUrl,
    BROWSER_TESTING: '1',
    BROWSER_TEST_USER_NAME: testUser.name,
    BROWSER_TEST_USER_EMAIL: testUser.email,
    BROWSER_TEST_USER_PASSWORD: testUser.password,
    BROWSER_TEST_EVENT_TITLE: testEventTitle,

    DB_URL: '',
    DB_CONNECTION: 'mysql',
    DB_HOST: '127.0.0.1',
    DB_PORT: '3306',
    DB_DATABASE: 'invitation_app_browser_testing',
    DB_CHARSET: 'utf8mb4',
    DB_COLLATION: 'utf8mb4_unicode_ci',
    DB_SOCKET: '',

    MAIL_MAILER: 'array',
    MAIL_HOST: '',
    MAIL_USERNAME: '',
    MAIL_PASSWORD: '',
    QUEUE_CONNECTION: 'database',
    SESSION_DRIVER: 'database',
    SESSION_SECURE_COOKIE: 'false',
    CACHE_STORE: 'database',

    INVITATION_CHANNELS: 'mail',
    INVITATIONS_WEBHOOK_SECRET: '',
    DELIVERY_LAB_ENABLED: 'false',
    DELIVERY_LAB_SANDBOX: 'false',
    SMS_ENDPOINT: '',
    SMS_TOKEN: '',
    SMS_SENDER: '',
    WHATSAPP_ENDPOINT: '',
    WHATSAPP_TOKEN: '',
    WHATSAPP_SENDER: '',

    POSTMARK_TOKEN: '',
    RESEND_KEY: '',
    AWS_ACCESS_KEY_ID: '',
    AWS_SECRET_ACCESS_KEY: '',
    SLACK_BOT_USER_OAUTH_TOKEN: '',
};
