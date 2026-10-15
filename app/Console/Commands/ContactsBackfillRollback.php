<?php

namespace App\Console\Commands;

use App\Models\Guest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Undo the guest links created by a specific `contacts:backfill` run.
 *
 * Provenance comes from the run journal written by the backfill: the exact
 * (guest_id, contact_id) pairs it created. Rollback only detaches a link when
 * the guest's current `contact_id` still equals the recorded value, so:
 *   - links that existed before the backfill are never touched,
 *   - links changed after the backfill are skipped, not clobbered,
 *   - a link is never guessed from the data.
 *
 * Contacts are never deleted, and no guest field other than `contact_id` is
 * modified. Preview by default; pass `--apply` to detach.
 */
class ContactsBackfillRollback extends Command
{
    protected $signature = 'contacts:backfill-rollback
        {run : The run id of the backfill to reverse}
        {--apply : Perform the rollback (without this the command only reports)}';

    protected $description = 'Detach only the guest links created by one contacts:backfill run (preview by default).';

    public function handle(): int
    {
        $runId = (string) $this->argument('run');
        $apply = (bool) $this->option('apply');
        $path = "contacts-backfill/{$runId}.json";

        if (! Storage::disk('local')->exists($path)) {
            $this->error("Run '{$runId}' was not found ({$path}).");

            return self::FAILURE;
        }

        $data = json_decode((string) Storage::disk('local')->get($path), true);
        $links = is_array($data) && is_array($data['links'] ?? null) ? $data['links'] : [];

        if ($links === []) {
            $this->warn("Run '{$runId}' recorded no links (dry run or failed). Nothing to roll back.");

            return self::SUCCESS;
        }

        $detachIds = [];
        $skippedChanged = 0;
        $skippedMissing = 0;

        foreach ($links as $link) {
            $guestId = (int) ($link['guest_id'] ?? 0);
            $contactId = (int) ($link['contact_id'] ?? 0);

            if ($guestId <= 0 || $contactId <= 0) {
                continue;
            }

            $guest = Guest::find($guestId);

            if (! $guest) {
                $skippedMissing++;
                continue;
            }

            // Only reverse the exact link this run created.
            if ((int) $guest->contact_id !== $contactId) {
                $skippedChanged++;
                continue;
            }

            $detachIds[] = $guestId;
        }

        $this->info(($apply ? 'Rolling back' : 'Previewing rollback of')." run {$runId}");
        $this->line('Links recorded: '.count($links));
        $this->line('Links to detach: '.count($detachIds));
        $this->line('Skipped (changed since backfill): '.$skippedChanged);
        $this->line('Skipped (guest missing): '.$skippedMissing);

        if (! $apply) {
            $this->warn('Preview only — re-run with --apply to detach.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($detachIds) {
            if ($detachIds !== []) {
                Guest::query()->whereIn('id', $detachIds)->update(['contact_id' => null]);
            }
        });

        // The run journal is left untouched: it remains the immutable record of
        // what the backfill created. Re-running the rollback is safe — already
        // detached links no longer match and are skipped.
        $this->info('Detached '.count($detachIds).' guest link(s). Contacts were not deleted.');

        return self::SUCCESS;
    }
}
