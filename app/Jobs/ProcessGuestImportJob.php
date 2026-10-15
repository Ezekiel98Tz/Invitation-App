<?php

namespace App\Jobs;

use App\Models\GuestImport;
use App\Notifications\GuestImportCompletedNotification;
use App\Notifications\GuestImportFailedNotification;
use App\Services\ContactResolver;
use App\Support\GuestDedup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

class ProcessGuestImportJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $importId,
        public string $filePath,
        public array $mapping = [],
    ) {}

    public function handle(): void
    {
        $import = GuestImport::with('event.user')->find($this->importId);

        if (! $import) {
            $this->deleteFile();

            return;
        }

        // Idempotency: never reprocess a finished import (e.g. on retry).
        if (in_array($import->status, ['completed', 'failed'], true)) {
            $this->deleteFile();

            return;
        }

        $import->forceFill(['status' => 'processing', 'started_at' => now()])->save();

        try {
            $event = $import->event;
            $accountId = (int) $event->user_id;
            $resolver = app(ContactResolver::class);
            $map = array_merge(['name' => 'name', 'email' => 'email', 'phone' => 'phone'], $this->mapping);
            $keys = GuestDedup::existingKeys($event);

            $created = 0;
            $skipped = 0;
            $failed = 0;

            $reader = SimpleExcelReader::create($this->filePath);

            $reader->getRows()
                ->chunk(100)
                ->each(function ($rows) use (&$created, &$skipped, &$failed, $event, $accountId, $resolver, $map, &$keys) {
                    foreach ($rows as $row) {
                        $name = trim((string) Arr::get($row, $map['name']));

                        if ($name === '') {
                            $skipped++;
                            continue;
                        }

                        $email = trim((string) Arr::get($row, $map['email']));
                        $phone = trim((string) Arr::get($row, $map['phone']));

                        $key = GuestDedup::key($name, $email);

                        if (isset($keys[$key])) {
                            $skipped++;
                            continue;
                        }

                        $keys[$key] = true;

                        $emailValue = $email !== '' ? $email : null;
                        $phoneValue = $phone !== '' ? $phone : null;

                        // Resolve/create the account contact and create the guest
                        // in one transaction, so a failed row leaves neither a
                        // guest nor an orphan contact behind.
                        $guest = DB::transaction(function () use ($resolver, $accountId, $event, $name, $emailValue, $phoneValue) {
                            $resolution = $resolver->resolve($accountId, $name, $emailValue, $phoneValue);

                            if ($resolution->isConflict()) {
                                // Email and phone match different contacts; skip the
                                // row rather than link it to either one.
                                return null;
                            }

                            $guest = $event->guests()->make([
                                'name' => $name,
                                'email' => $emailValue,
                                'phone' => $phoneValue,
                                'status' => 'pending',
                            ]);

                            $guest->contact_id = $resolution->contactId();
                            $guest->save();

                            return $guest;
                        });

                        if ($guest === null) {
                            // Email and phone matched different contacts in the
                            // account. Counted as a failed (conflict) row, kept
                            // distinct from routine blank/duplicate skips so the
                            // operator can act on it. No guest or contact is
                            // created for this row.
                            $failed++;
                            continue;
                        }

                        $created++;
                    }
                });

            $reader->close();
            $this->deleteFile();

            $import->forceFill([
                'status' => 'completed',
                'created_count' => $created,
                'skipped_count' => $skipped,
                'failed_count' => $failed,
                'finished_at' => now(),
            ])->save();

            $event->user?->notify(new GuestImportCompletedNotification($event, $created, $skipped, $failed));
        } catch (Throwable $e) {
            $this->deleteFile();

            $import->forceFill([
                'status' => 'failed',
                'error' => 'The file could not be processed. Please check the format and column headers and try again.',
                'finished_at' => now(),
            ])->save();

            if ($import->event?->user) {
                $import->event->user->notify(new GuestImportFailedNotification(
                    $import->event,
                    'The file could not be processed. Please check the format and column headers and try again.'
                ));
            }

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $import = GuestImport::with('event.user')->find($this->importId);

        if ($import && $import->status !== 'failed') {
            $import->forceFill([
                'status' => 'failed',
                'error' => 'The import failed unexpectedly. Please try again.',
                'finished_at' => now(),
            ])->save();
        }

        $this->deleteFile();
    }

    private function deleteFile(): void
    {
        if ($this->filePath !== '' && File::exists($this->filePath)) {
            File::delete($this->filePath);
        }
    }
}
