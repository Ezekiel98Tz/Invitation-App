<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class EventsCleanupPast extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:cleanup-past';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Soft delete events older than 6 months';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cutoff = Carbon::now()->subMonths(6);

        $deleted = Event::query()
            ->where('event_start', '<=', $cutoff)
            ->delete();

        $this->info('Deleted events: '.$deleted);

        return self::SUCCESS;
    }
}
