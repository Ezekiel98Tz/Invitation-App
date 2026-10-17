<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fail-closed checks for the Playwright-launched Laravel server and its
 * one-off setup commands. PHPUnit has its own guard in Tests\TestCase.
 */
class BrowserTestEnvironment
{
    public const DATABASE = 'invitation_app_browser_testing';
    public const APP_URL = 'http://127.0.0.1:8001';
    public const PORT = '3306';
    public const APPROVED_HOSTS = ['127.0.0.1', 'localhost'];

    /**
     * Verify the effective application configuration, then prove the actual
     * connection is the dedicated browser-test database.
     *
     * @return array{database: string, host: string, port: string, server: string}
     */
    public static function verify(): array
    {
        $connection = config('database.connections.mysql');
        $connection = is_array($connection) ? $connection : [];
        $invitationChannels = array_values((array) config('invitations.channels', []));
        sort($invitationChannels);

        $checks = [
            'APP_ENV must be testing' => app()->environment('testing'),
            'browser-test mode must be enabled' => config('app.browser_testing') === true,
            'application URL must be the local Playwright server' => rtrim((string) config('app.url'), '/') === self::APP_URL,
            'default database connection must be mysql' => config('database.default') === 'mysql',
            'mysql driver must be mysql' => ($connection['driver'] ?? null) === 'mysql',
            'database name must be '.self::DATABASE => ($connection['database'] ?? null) === self::DATABASE,
            'database host must be local' => in_array((string) ($connection['host'] ?? ''), self::APPROVED_HOSTS, true),
            'database port must be '.self::PORT => (string) ($connection['port'] ?? '') === self::PORT,
            'mail must use the array driver' => config('mail.default') === 'array',
            'queue must be database-backed and have no worker' => config('queue.default') === 'database',
            'sessions must use the isolated database' => config('session.driver') === 'database',
            'cache must use the isolated database' => config('cache.default') === 'database',
            'only mail may be configured as an invitation channel' => $invitationChannels === ['mail'],
            'Delivery Lab must be disabled' => config('delivery_lab.enabled') !== true,
            'SMS endpoint must be empty' => blank(config('invitations.sms.endpoint')),
            'SMS token must be empty' => blank(config('invitations.sms.token')),
            'WhatsApp endpoint must be empty' => blank(config('invitations.whatsapp.endpoint')),
            'WhatsApp token must be empty' => blank(config('invitations.whatsapp.token')),
            'delivery webhook secret must be empty' => blank(config('invitations.webhook_secret')),
            'no Postmark token may be configured' => blank(config('services.postmark.token')),
            'no Resend token may be configured' => blank(config('services.resend.key')),
            'no SES credentials may be configured' => blank(config('services.ses.key')) && blank(config('services.ses.secret')),
            'no Slack token may be configured' => blank(config('services.slack.notifications.bot_user_oauth_token')),
            'fixture email must use the reserved .test domain' => str_ends_with((string) config('browser_testing.user_email'), '@example.test'),
            'empty-state fixture email must use the reserved .test domain' => str_ends_with((string) config('browser_testing.empty_user_email'), '@example.test'),
            'staff fixture email must use the reserved .test domain' => str_ends_with((string) config('browser_testing.staff_user_email'), '@example.test'),
            'fixture password must be supplied via BROWSER_TEST_USER_PASSWORD and be at least 12 characters' => is_string(config('browser_testing.user_password')) && mb_strlen(config('browser_testing.user_password')) >= 12,
            'fixture event title must be set' => trim((string) config('browser_testing.event_title')) !== '',
            'fixture celebration name must be set' => trim((string) config('browser_testing.celebration_name')) !== '',
            'standalone fixture event title must be set' => trim((string) config('browser_testing.standalone_event_title')) !== '',
            'past fixture event title must be set' => trim((string) config('browser_testing.past_event_title')) !== '',
            'upcoming fixture event title must be set' => trim((string) config('browser_testing.upcoming_event_title')) !== '',
        ];

        $failed = array_keys(array_filter($checks, static fn (bool $ok): bool => ! $ok));

        if ($failed !== []) {
            throw new RuntimeException(
                "Refusing browser-test operation:\n  - ".implode("\n  - ", $failed)
            );
        }

        try {
            $effective = DB::selectOne('select database() as database_name, version() as server_version');
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Refusing browser-test operation: the configured database is not reachable.',
                0,
                $exception
            );
        }

        if (($effective->database_name ?? null) !== self::DATABASE) {
            throw new RuntimeException(
                'Refusing browser-test operation: the live connection is not '.self::DATABASE.'.'
            );
        }

        return [
            'database' => self::DATABASE,
            'host' => (string) ($connection['host'] ?? ''),
            'port' => (string) ($connection['port'] ?? ''),
            'server' => (string) ($effective->server_version ?? 'unknown'),
        ];
    }
}
