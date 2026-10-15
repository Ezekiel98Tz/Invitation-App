<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\BuildsContactSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Report contacts linkage and verify that a backfill changed nothing except the
 * intended contacts and guest links.
 *
 * With `--run=<id>` the command compares the current database against the
 * snapshot the backfill captured before it applied. The guest id, token, guest
 * field, RSVP and delivery fingerprints must be identical; only the contact and
 * link counts are expected to grow. Any mismatch is reported and fails the
 * command.
 */
class ContactsVerify extends Command
{
    use BuildsContactSnapshot;

    protected $signature = 'contacts:verify
        {--run= : Compare the current database against this backfill run\'s pre-run snapshot}';

    protected $description = 'Report contacts linkage and verify parity after a backfill.';

    public function handle(): int
    {
        $current = $this->contactSnapshot();
        $integrity = $this->contactIntegrity();

        $this->info('Current state');
        $this->line(sprintf(
            'Guests: %d (linked: %d, unlinked: %d)',
            $current['guest_count'],
            $current['linked_guests'],
            $current['unlinked_guests']
        ));
        $this->line(sprintf('Contacts: %d', $current['contact_count']));
        $this->line(sprintf('RSVPs: %d, deliveries: %d', $current['rsvp_count'], $current['delivery_count']));
        $this->line(sprintf(
            'Imports: %d, activity logs: %d',
            $current['guest_import_count'],
            $current['activity_log_count']
        ));

        $problems = [];

        foreach ($integrity as $name => $value) {
            if ($value > 0) {
                $problems[] = "integrity: {$name} = {$value}";
            }
        }

        $runId = (string) ($this->option('run') ?? '');

        if ($runId !== '') {
            $path = "contacts-backfill/{$runId}.json";

            if (! Storage::disk('local')->exists($path)) {
                $this->error("Run '{$runId}' was not found ({$path}).");

                return self::FAILURE;
            }

            $data = json_decode((string) Storage::disk('local')->get($path), true);
            $before = is_array($data) ? ($data['snapshot'] ?? null) : null;

            if (! is_array($before)) {
                $this->error("Run '{$runId}' has no snapshot to compare against.");

                return self::FAILURE;
            }

            $this->newLine();
            $this->info(sprintf(
                'Comparing against run %s (status: %s)',
                $runId,
                (string) ($data['status'] ?? 'unknown')
            ));

            // These must be byte-identical: nothing operational may change.
            $mustMatch = [
                'guest_count',
                'guest_id_fingerprint',
                'token_fingerprint',
                'guest_field_fingerprint',
                'rsvp_count',
                'rsvp_fingerprint',
                'delivery_count',
                'delivery_fingerprint',
                'guest_import_count',
                'guest_import_fingerprint',
                'activity_log_count',
                'activity_log_fingerprint',
            ];

            foreach ($mustMatch as $field) {
                $old = $before[$field] ?? null;
                $new = $current[$field] ?? null;

                if ((string) $old !== (string) $new) {
                    $problems[] = sprintf(
                        'unexpected change: %s was %s, now %s',
                        $field,
                        $this->render($old),
                        $this->render($new)
                    );
                }
            }

            // These are expected to grow (or stay equal) but never shrink.
            if ($current['contact_count'] < (int) ($before['contact_count'] ?? 0)) {
                $problems[] = sprintf(
                    'unexpected change: contact_count decreased from %d to %d',
                    (int) ($before['contact_count'] ?? 0),
                    $current['contact_count']
                );
            }

            if ($current['linked_guests'] < (int) ($before['linked_guests'] ?? 0)) {
                $problems[] = sprintf(
                    'unexpected change: linked_guests decreased from %d to %d',
                    (int) ($before['linked_guests'] ?? 0),
                    $current['linked_guests']
                );
            }

            $this->line(sprintf(
                'Contacts: %d → %d',
                (int) ($before['contact_count'] ?? 0),
                $current['contact_count']
            ));
            $this->line(sprintf(
                'Linked guests: %d → %d',
                (int) ($before['linked_guests'] ?? 0),
                $current['linked_guests']
            ));
        }

        if ($problems !== []) {
            $this->newLine();
            $this->error('Verification FAILED:');
            foreach ($problems as $problem) {
                $this->line(' - '.$problem);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Verification passed: no unexpected changes and integrity checks are clean.');

        return self::SUCCESS;
    }

    private function render(mixed $value): string
    {
        return is_scalar($value) || $value === null
            ? (string) $value
            : (string) json_encode($value);
    }
}
