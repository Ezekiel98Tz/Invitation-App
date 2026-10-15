<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\BuildsContactSnapshot;
use App\Models\Contact;
use App\Models\Guest;
use App\Support\ContactNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Link existing event guests to reusable account contacts.
 *
 * Dry run by default: without `--apply` nothing is written. The command never
 * runs automatically — it must be invoked explicitly, and it is not wired into
 * migrations, boot, requests, or the scheduler.
 *
 * It is idempotent: guests that already have a contact are skipped, and a
 * matching contact is reused rather than duplicated. The whole run is applied
 * inside a single transaction so a failure leaves no partial result.
 */
class ContactsBackfill extends Command
{
    use BuildsContactSnapshot;

    protected $signature = 'contacts:backfill
        {--apply : Persist the contacts and guest links (without this the command only reports)}
        {--chunk=500 : Number of guests processed per batch}
        {--run= : Optional explicit run id, used as the rollback journal name}';

    protected $description = 'Link existing event guests to reusable account contacts (dry run by default).';

    /**
     * Per-run in-memory match index, loaded lazily per account.
     *
     * @var array<int, array<string, int>>
     */
    private array $emailIndex = [];

    /** @var array<int, array<string, int>> */
    private array $phoneIndex = [];

    /** @var array<int, bool> */
    private array $loadedAccounts = [];

    private int $syntheticId = 0;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $startedAt = now();

        $suppliedRunId = $this->option('run');
        $suppliedRunId = is_string($suppliedRunId) ? trim($suppliedRunId) : '';

        if ($suppliedRunId !== '') {
            if (! preg_match('/^[A-Za-z0-9._-]+$/', $suppliedRunId)) {
                $this->error('Invalid run id. Use only letters, digits, dot, underscore or hyphen.');

                return self::FAILURE;
            }

            if (Storage::disk('local')->exists($this->journalPath($suppliedRunId))) {
                $this->error("Run id '{$suppliedRunId}' already exists. Run journals are never overwritten — choose another id or omit --run.");

                return self::FAILURE;
            }

            $runId = $suppliedRunId;
        } else {
            $runId = $this->uniqueRunId();
        }

        $this->emailIndex = [];
        $this->phoneIndex = [];
        $this->loadedAccounts = [];
        $this->syntheticId = 0;

        $this->info($apply ? 'contacts:backfill — APPLY' : 'contacts:backfill — DRY RUN');
        $this->line('Run id: '.$runId);

        $snapshot = $this->contactSnapshot();

        $totals = $this->emptyTotals();
        $links = [];
        $conflicts = [];

        try {
            DB::transaction(function () use ($chunkSize, $apply, &$totals, &$links, &$conflicts) {
                Guest::query()
                    ->with([
                        'event:id,user_id',
                        'contact:id,user_id,normalized_email,normalized_phone',
                    ])
                    ->chunkById($chunkSize, function (Collection $guests) use ($apply, &$totals, &$links, &$conflicts) {
                        $result = $this->processChunk($guests, $apply);

                        foreach ($result['totals'] as $key => $value) {
                            $totals[$key] += $value;
                        }

                        $links = array_merge($links, $result['links']);
                        $conflicts = array_merge($conflicts, $result['conflicts']);
                    });
            });
        } catch (Throwable $e) {
            if ($apply) {
                $this->persistRun($runId, [
                    'run_id' => $runId,
                    'command' => 'contacts:backfill',
                    'mode' => 'apply',
                    'status' => 'failed',
                    'started_at' => $startedAt->toIso8601String(),
                    'finished_at' => now()->toIso8601String(),
                    'error' => $e->getMessage(),
                    'counts' => $totals,
                    'snapshot' => $snapshot,
                    'links' => [],
                ]);
            }

            $this->error('Backfill failed: '.$e->getMessage());
            $this->line('The run is atomic — no contacts or links were committed.');

            return self::FAILURE;
        }

        if ($apply) {
            $this->persistRun($runId, [
                'run_id' => $runId,
                'command' => 'contacts:backfill',
                'mode' => 'apply',
                'status' => 'completed',
                'started_at' => $startedAt->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
                'counts' => $totals,
                'snapshot' => $snapshot,
                'links' => $links,
            ]);
        }

        $this->renderSummary($totals, $apply, $runId, $conflicts);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Guest>  $guests
     * @return array{totals: array<string, int>, links: list<array{guest_id: int, contact_id: int}>, conflicts: list<string>}
     */
    protected function processChunk(Collection $guests, bool $apply): array
    {
        $totals = $this->emptyTotals();
        $links = [];
        $conflicts = [];

        foreach ($guests as $guest) {
            $totals['guests_scanned']++;

            $accountId = (int) ($guest->event->user_id ?? 0);
            if ($accountId <= 0) {
                continue;
            }

            $emailKey = ContactNormalizer::email($guest->email);
            $rawPhone = trim((string) ($guest->phone ?? ''));
            $phoneKey = ContactNormalizer::phone($guest->phone);

            if ($rawPhone !== '' && $phoneKey === null) {
                $totals['unusable_phone']++;
            }

            // Existing links are never touched or re-pointed.
            if ($guest->contact_id !== null) {
                $totals['already_linked']++;
                if ($this->linkedContactIsInconsistent($guest, $emailKey, $phoneKey)) {
                    $totals['inconsistent_links']++;
                    $conflicts[] = sprintf(
                        'guest #%d (%s): existing link to contact #%d contradicts its email/phone',
                        $guest->id,
                        $guest->name,
                        $guest->contact_id
                    );
                }
                continue;
            }

            $this->loadAccount($accountId);

            $idByEmail = $emailKey !== null ? ($this->emailIndex[$accountId][$emailKey] ?? null) : null;
            $idByPhone = $phoneKey !== null ? ($this->phoneIndex[$accountId][$phoneKey] ?? null) : null;

            // Email identifies one contact while phone identifies a different
            // one: flag, never merge or silently pick a side.
            if ($idByEmail !== null && $idByPhone !== null && $idByEmail !== $idByPhone) {
                $totals['conflicts']++;
                $conflicts[] = sprintf(
                    'guest #%d (%s): email matches contact #%d but phone matches contact #%d',
                    $guest->id,
                    $guest->name,
                    $idByEmail,
                    $idByPhone
                );
                continue;
            }

            $contactId = $idByEmail ?? $idByPhone;

            if ($contactId !== null) {
                $idByEmail !== null ? $totals['linked_email']++ : $totals['linked_phone']++;
                $this->linkGuest($guest, $contactId, $apply, $totals, $links);
                continue;
            }

            // No match. A guest with no usable identifier still gets its own
            // contact so it is never merged with anyone else.
            $hasKey = $emailKey !== null || $phoneKey !== null;
            $hasKey ? $totals['created_with_keys']++ : $totals['created_individual']++;

            $newId = $this->createContact($accountId, $guest, $emailKey, $phoneKey, $apply);

            if ($emailKey !== null) {
                $this->emailIndex[$accountId][$emailKey] = $newId;
            }
            if ($phoneKey !== null) {
                $this->phoneIndex[$accountId][$phoneKey] = $newId;
            }

            if ($apply) {
                $totals['contacts_created']++;
            }

            $this->linkGuest($guest, $newId, $apply, $totals, $links);
        }

        return ['totals' => $totals, 'links' => $links, 'conflicts' => $conflicts];
    }

    private function linkedContactIsInconsistent(Guest $guest, ?string $emailKey, ?string $phoneKey): bool
    {
        $contact = $guest->contact;

        if (! $contact) {
            return false;
        }

        if ($emailKey !== null && $contact->normalized_email !== null && $contact->normalized_email !== $emailKey) {
            return true;
        }

        if ($phoneKey !== null && $contact->normalized_phone !== null && $contact->normalized_phone !== $phoneKey) {
            return true;
        }

        return false;
    }

    private function loadAccount(int $accountId): void
    {
        if (isset($this->loadedAccounts[$accountId])) {
            return;
        }

        $this->loadedAccounts[$accountId] = true;
        $this->emailIndex[$accountId] = [];
        $this->phoneIndex[$accountId] = [];

        Contact::query()
            ->where('user_id', $accountId)
            ->get(['id', 'normalized_email', 'normalized_phone'])
            ->each(function (Contact $contact) use ($accountId) {
                if ($contact->normalized_email !== null) {
                    $this->emailIndex[$accountId][$contact->normalized_email] = (int) $contact->id;
                }
                if ($contact->normalized_phone !== null) {
                    $this->phoneIndex[$accountId][$contact->normalized_phone] = (int) $contact->id;
                }
            });
    }

    private function createContact(int $accountId, Guest $guest, ?string $emailKey, ?string $phoneKey, bool $apply): int
    {
        // In a dry run, return a synthetic (negative) id so later guests behave
        // exactly as they would after an apply, without writing anything.
        if (! $apply) {
            return --$this->syntheticId;
        }

        $contact = new Contact();
        $contact->user_id = $accountId;
        $contact->name = (string) $guest->name;
        $contact->email = $guest->email;
        $contact->phone = $guest->phone;
        $contact->normalized_email = $emailKey;
        $contact->normalized_phone = $phoneKey;
        $contact->save();

        return (int) $contact->id;
    }

    /**
     * @param  array<string, int>  $totals
     * @param  list<array{guest_id: int, contact_id: int}>  $links
     */
    private function linkGuest(Guest $guest, int $contactId, bool $apply, array &$totals, array &$links): void
    {
        if (! $apply) {
            return;
        }

        $guest->contact_id = $contactId;
        $guest->save();

        $links[] = ['guest_id' => (int) $guest->id, 'contact_id' => $contactId];
        $totals['links_created']++;
    }

    /**
     * @return array<string, int>
     */
    private function emptyTotals(): array
    {
        return [
            'guests_scanned' => 0,
            'already_linked' => 0,
            'linked_email' => 0,
            'linked_phone' => 0,
            'created_with_keys' => 0,
            'created_individual' => 0,
            'conflicts' => 0,
            'unusable_phone' => 0,
            'inconsistent_links' => 0,
            'contacts_created' => 0,
            'links_created' => 0,
        ];
    }

    private function generateRunId(): string
    {
        return now()->format('Ymd-His').'-'.Str::lower(Str::random(8));
    }

    /**
     * Generate a run id whose journal does not already exist. A supplied id is
     * never modified, but a generated one must be collision-free.
     */
    private function uniqueRunId(): string
    {
        do {
            $candidate = $this->generateRunId();
        } while (Storage::disk('local')->exists($this->journalPath($candidate)));

        return $candidate;
    }

    private function journalPath(string $runId): string
    {
        return "contacts-backfill/{$runId}.json";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistRun(string $runId, array $payload): void
    {
        Storage::disk('local')->put(
            $this->journalPath($runId),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param  array<string, int>  $totals
     * @param  list<string>  $conflicts
     */
    private function renderSummary(array $totals, bool $apply, string $runId, array $conflicts): void
    {
        $planned = $totals['linked_email']
            + $totals['linked_phone']
            + $totals['created_with_keys']
            + $totals['created_individual'];

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['Guests scanned', $totals['guests_scanned']],
            ['Already linked (preserved)', $totals['already_linked']],
            ['Linked by email', $totals['linked_email']],
            ['Linked by phone', $totals['linked_phone']],
            ['New contacts (had a key)', $totals['created_with_keys']],
            ['New contacts (no usable key)', $totals['created_individual']],
            ['Conflicts (not linked)', $totals['conflicts']],
            ['Inconsistent existing links (reported)', $totals['inconsistent_links']],
            ['Unusable phone values', $totals['unusable_phone']],
        ]);

        if ($apply) {
            $this->line('Contacts created: '.$totals['contacts_created']);
            $this->line('Guest links created: '.$totals['links_created']);
            $this->info('Applied. Run id: '.$runId);
        } else {
            $this->line('Would link: '.$planned);
            $this->warn('Dry run only — re-run with --apply to persist.');
        }

        if ($conflicts !== []) {
            $this->newLine();
            $this->warn('Flagged for review (no merge performed):');
            foreach (array_slice($conflicts, 0, 20) as $conflict) {
                $this->line(' - '.$conflict);
            }
            if (count($conflicts) > 20) {
                $this->line(' - …and '.(count($conflicts) - 20).' more');
            }
        }
    }
}
