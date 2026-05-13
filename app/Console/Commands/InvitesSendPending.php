<?php

namespace App\Console\Commands;

use App\Jobs\SendInvitationJob;
use App\Models\Guest;
use Illuminate\Console\Command;

class InvitesSendPending extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invites:send-pending';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan for guests with sent_at = null, dispatch SendInvitationJob';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = 0;

        Guest::query()
            ->unsent()
            ->orderBy('id')
            ->chunk(500, function ($guests) use (&$count) {
                foreach ($guests as $guest) {
                    dispatch(new SendInvitationJob($guest));
                    $count++;
                }
            });

        $this->info('Queued invitations: '.$count);

        return self::SUCCESS;
    }
}
