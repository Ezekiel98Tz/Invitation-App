<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Guest;
use App\Notifications\GuestImportCompletedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Spatie\SimpleExcel\SimpleExcelReader;

class ProcessGuestImportJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    public function __construct(
        public string $filePath,
        public int $eventId,
        public array $mapping = [],
    ) {}

    public function handle(): void
    {
        $event = Event::with('user')->findOrFail($this->eventId);

        $map = array_merge(
            ['name' => 'name', 'email' => 'email', 'phone' => 'phone'],
            $this->mapping
        );

        $created = 0;
        $skipped = 0;

        $reader = SimpleExcelReader::create($this->filePath);

        $reader->getRows()
            ->chunk(100)
            ->each(function ($rows) use (&$created, &$skipped, $event, $map) {
                foreach ($rows as $row) {
                    $name = trim((string) Arr::get($row, $map['name']));

                    if ($name === '') {
                        $skipped++;
                        continue;
                    }

                    Guest::create([
                        'event_id' => $event->id,
                        'name' => $name,
                        'email' => ($email = trim((string) Arr::get($row, $map['email']))) !== '' ? $email : null,
                        'phone' => ($phone = trim((string) Arr::get($row, $map['phone']))) !== '' ? $phone : null,
                        'status' => 'pending',
                    ]);

                    $created++;
                }
            });

        $reader->close();

        if (File::exists($this->filePath)) {
            File::delete($this->filePath);
        }

        $event->user->notify(new GuestImportCompletedNotification($event, $created, $skipped));
    }
}
