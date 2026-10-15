<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The only database the automated test suite is permitted to run against.
     */
    protected const APPROVED_TEST_DATABASE = 'invitation_app_testing';

    /**
     * The only database connection driver the suite is permitted to use.
     */
    protected const APPROVED_DB_CONNECTION = 'mysql';

    /**
     * The only database hosts the suite is permitted to connect to
     * (the local XAMPP MySQL instance).
     *
     * @var list<string>
     */
    protected const APPROVED_DB_HOSTS = ['127.0.0.1', 'localhost'];

    /**
     * The only database port the suite is permitted to connect to
     * (the local XAMPP MySQL instance).
     */
    protected const APPROVED_DB_PORT = '3306';

    protected function setUp(): void
    {
        $this->guardAgainstUnsafeTestDatabase();

        parent::setUp();
    }

    /**
     * Refuse to run if the effective configuration could point at a development
     * or production database.
     *
     * This executes before parent::setUp(), which boots the application and
     * invokes database-refreshing traits (e.g. RefreshDatabase) that run
     * destructive migrations. No destructive database operation can therefore
     * occur against an unapproved database.
     *
     * It fails closed: any missing or unexpected value is treated as a refusal.
     */
    protected function guardAgainstUnsafeTestDatabase(): void
    {
        $environment = $this->rawEnvironmentValue('APP_ENV');
        $connection = $this->rawEnvironmentValue('DB_CONNECTION');
        $database = $this->rawEnvironmentValue('DB_DATABASE');
        $host = $this->rawEnvironmentValue('DB_HOST');
        $port = $this->rawEnvironmentValue('DB_PORT');

        $problems = [];

        if ($environment !== 'testing') {
            $problems[] = sprintf("APP_ENV must be 'testing', got '%s'.", $environment ?? 'null');
        }

        if ($connection !== self::APPROVED_DB_CONNECTION) {
            $problems[] = sprintf(
                "DB_CONNECTION must be '%s', got '%s'.",
                self::APPROVED_DB_CONNECTION,
                $connection ?? 'null'
            );
        }

        if ($database !== self::APPROVED_TEST_DATABASE) {
            $problems[] = sprintf(
                "DB_DATABASE must be '%s', got '%s'.",
                self::APPROVED_TEST_DATABASE,
                $database ?? 'null'
            );
        }

        if ($host === null || ! in_array(strtolower($host), self::APPROVED_DB_HOSTS, true)) {
            $problems[] = sprintf(
                "DB_HOST must be one of [%s], got '%s'.",
                implode(', ', self::APPROVED_DB_HOSTS),
                $host ?? 'null'
            );
        }

        if ($port !== self::APPROVED_DB_PORT) {
            $problems[] = sprintf(
                "DB_PORT must be '%s', got '%s'.",
                self::APPROVED_DB_PORT,
                $port ?? 'null'
            );
        }

        if ($problems !== []) {
            throw new RuntimeException(
                "Refusing to run tests against an unapproved database configuration:\n  - ".
                implode("\n  - ", $problems).
                "\nCreate the dedicated '".self::APPROVED_TEST_DATABASE.
                "' database and confirm the testing configuration in 'phpunit.xml' before running the suite."
            );
        }
    }

    /**
     * Read a value from the process environment without booting the application.
     *
     * PHPUnit's <env force="true"> entries are written to both putenv() and
     * $_ENV (see PhpUnit's PhpHandler::handleEnvVariables()). Laravel's .env
     * loader is immutable, so it will not overwrite those values. Reading
     * $_ENV, then $_SERVER, then getenv() therefore reflects the configuration
     * the application will actually use.
     */
    protected function rawEnvironmentValue(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
