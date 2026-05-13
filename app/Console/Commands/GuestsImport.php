<?php

namespace App\Console\Commands;

use App\Jobs\ProcessGuestImportJob;
use Illuminate\Support\Facades\File;
use Illuminate\Console\Command;

class GuestsImport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'guests:import {event-id} {file} {--mapping=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manual import from command line';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $eventId = (int) $this->argument('event-id');
        $file = (string) $this->argument('file');

        if (! File::exists($file)) {
            $this->error('File not found: '.$file);
            return self::FAILURE;
        }

        $mapping = [];
        $rawMapping = $this->option('mapping');

        if (is_string($rawMapping) && $rawMapping !== '') {
            $decoded = json_decode($rawMapping, true);
            if (is_array($decoded)) {
                $mapping = $decoded;
            }
        }

        dispatch(new ProcessGuestImportJob($file, $eventId, $mapping));

        $this->info('Import queued.');

        return self::SUCCESS;
    }
}
