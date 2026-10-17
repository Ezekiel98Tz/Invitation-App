<?php

namespace App\Console\Commands;

use App\Support\BrowserTestEnvironment;
use Illuminate\Console\Command;
use RuntimeException;

class VerifyBrowserTestEnvironment extends Command
{
    protected $signature = 'browser-test:verify';

    protected $description = 'Verify the effective configuration is safe for isolated browser tests';

    public function handle(): int
    {
        try {
            $result = BrowserTestEnvironment::verify();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Check', 'Value'], [
            ['Database', $result['database']],
            ['Host', $result['host']],
            ['Port', $result['port']],
            ['MySQL server', $result['server']],
            ['Mail', 'array capture'],
            ['Queue', 'database (no worker)'],
        ]);
        $this->info('Browser-test environment is safe.');

        return self::SUCCESS;
    }
}
