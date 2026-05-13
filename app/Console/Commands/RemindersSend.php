<?php

namespace App\Console\Commands;

use App\Jobs\SendReminderJob;
use App\Models\Guest;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RemindersSend extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reminders:send';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan for guests with pending > 2 days and not reminded, dispatch SendReminderJob';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays(2);
        $count = 0;

        Guest::query()
            ->pending()
            ->unreminded()
            ->whereNotNull('sent_at')
            ->where('sent_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunk(500, function ($guests) use (&$count) {
                foreach ($guests as $guest) {
                    dispatch(new SendReminderJob($guest));
                    $count++;
                }
            });

        $this->info('Queued reminders: '.$count);

        return self::SUCCESS;
    }
}
