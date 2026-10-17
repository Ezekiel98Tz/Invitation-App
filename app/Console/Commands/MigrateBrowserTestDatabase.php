<?php

namespace App\Console\Commands;

use App\Support\BrowserTestEnvironment;
use Illuminate\Console\Command;
use RuntimeException;

class MigrateBrowserTestDatabase extends Command
{
    protected $signature = 'browser-test:migrate';

    protected $description = 'Verify the browser-test environment and run normal migrations on it only';

    public function handle(): int
    {
        try {
            $result = BrowserTestEnvironment::verify();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Verified database: '.$result['database']);
        $this->line('Running normal migrations (no fresh/wipe) on the verified browser-test database.');

        return $this->call('migrate', ['--force' => true]);
    }
}
