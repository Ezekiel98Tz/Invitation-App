<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGuestImportJob;
use App\Jobs\SendInvitationJob;
use App\Jobs\SendReminderJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestImport;
use App\Services\ContactResolver;
use App\Support\AuditLogger;
use App\Support\ContactNormalizer;
use App\Support\GuestDedup;
use App\Support\PageSize;
use App\Support\InvitationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Throwable;

class GuestController extends Controller
{
    private const ALLOWED_IMPORT_EXTENSIONS = ['csv', 'xlsx', 'ods'];

    public function __construct(private readonly ContactResolver $contacts)
    {
    }

    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $query = $event->guests()->with('rsvp')->orderBy('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                    ->orWhere('email', 'like', $search);
            });
        }

        // Status counts for the whole event (unfiltered) so the UI can show
        // meaningful totals without issuing extra requests.
        $statusCounts = $event->guests()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [
            'total' => (int) $statusCounts->sum(),
            'pending' => (int) ($statusCounts['pending'] ?? 0),
            'accepted' => (int) ($statusCounts['accepted'] ?? 0),
            'declined' => (int) ($statusCounts['declined'] ?? 0),
        ];

        return response()->json(array_merge(
            $query->paginate(PageSize::resolve($request))->toArray(),
            ['counts' => $counts]
        ));
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $email = isset($data['email']) ? trim((string) $data['email']) : '';

        if (GuestDedup::exists($event, $data['name'], $email)) {
            throw ValidationException::withMessages([
                $email !== '' ? 'email' : 'name' => [
                    'A guest with these details is already on this event.',
                ],
            ]);
        }

        $name = $data['name'];
        $emailValue = $email !== '' ? $email : null;
        $phoneValue = isset($data['phone']) && trim((string) $data['phone']) !== '' ? trim((string) $data['phone']) : null;

        // Resolve/create the reusable account contact and create the guest in one
        // transaction so a failure never leaves an unintended contact behind.
        $guest = DB::transaction(function () use ($event, $name, $emailValue, $phoneValue) {
            $resolution = $this->contacts->resolve((int) $event->user_id, $name, $emailValue, $phoneValue);

            if ($resolution->isConflict()) {
                throw ValidationException::withMessages([
                    'email' => ['This guest\'s email and phone match different contacts in this account. Resolve the conflict before adding the guest.'],
                ]);
            }

            $guest = $event->guests()->make([
                'name' => $name,
                'email' => $emailValue,
                'phone' => $phoneValue,
                'status' => 'pending',
            ]);

            // contact_id is not mass-assignable; set it explicitly in trusted code.
            $guest->contact_id = $resolution->contactId();
            $guest->save();

            return $guest;
        });

        AuditLogger::log($request, 'guests.create', $guest);

        return response()->json($guest->load('rsvp'), 201);
    }

    public function update(Request $request, Event $event, Guest $guest): JsonResponse
    {
        $this->authorize('update', $event);

        if ($guest->event_id !== $event->id) {
            abort(404);
        }

        $this->authorize('update', $guest);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $name = $data['name'] ?? $guest->name;
        $email = array_key_exists('email', $data) ? trim((string) $data['email']) : (string) $guest->email;

        if (GuestDedup::exists($event, $name, $email, $guest->id)) {
            throw ValidationException::withMessages([
                $email !== '' ? 'email' : 'name' => [
                    'Another guest on this event already has these details.',
                ],
            ]);
        }

        $emailValue = $email !== '' ? $email : null;
        $phoneValue = array_key_exists('phone', $data)
            ? (trim((string) $data['phone']) !== '' ? trim((string) $data['phone']) : null)
            : $guest->phone;

        // The guest keeps its existing contact association. Never reassign or
        // merge; a changed identifier that matches a different contact is a
        // conflict and leaves the guest untouched.
        $this->guardContactAssociation($event, $guest, $emailValue, $phoneValue);

        $guest->fill([
            'name' => $name,
            'email' => $emailValue,
            'phone' => $phoneValue,
        ])->save();

        AuditLogger::log($request, 'guests.update', $guest);

        return response()->json($guest->fresh('rsvp'));
    }

    /**
     * Preserve the guest's existing contact association (never reassign/merge).
     *
     * A conflict is raised only when a *changed* identifier now matches a
     * different contact within the same account. Name-only changes and changes
     * to a brand-new value are allowed. The association itself is never altered.
     */
    private function guardContactAssociation(Event $event, Guest $guest, ?string $email, ?string $phone): void
    {
        if ($guest->contact_id === null) {
            return;
        }

        $accountId = (int) $event->user_id;
        $linkedContactId = (int) $guest->contact_id;

        $emailChanged = ContactNormalizer::email($email) !== ContactNormalizer::email($guest->email);
        $phoneChanged = ContactNormalizer::phone($phone) !== ContactNormalizer::phone($guest->phone);

        if (! $emailChanged && ! $phoneChanged) {
            return;
        }

        $conflict = false;

        if ($emailChanged) {
            $match = $this->contacts->findContactByEmail($accountId, $email);
            $conflict = $conflict || ($match !== null && (int) $match->id !== $linkedContactId);
        }

        if ($phoneChanged) {
            $match = $this->contacts->findContactByPhone($accountId, $phone);
            $conflict = $conflict || ($match !== null && (int) $match->id !== $linkedContactId);
        }

        if ($conflict) {
            throw ValidationException::withMessages([
                'email' => ['These changes match a different contact in this account. The guest and its contact association were left unchanged.'],
            ]);
        }
    }

    public function destroy(Request $request, Event $event, Guest $guest): JsonResponse
    {
        $this->authorize('update', $event);

        if ($guest->event_id !== $event->id) {
            abort(404);
        }

        $this->authorize('delete', $guest);

        $title = $guest->name;
        $guest->delete();

        AuditLogger::log($request, 'guests.delete', $event, ['name' => $title]);

        return response()->json(['deleted' => true]);
    }

    public function import(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'mapping' => ['nullable', 'array'],
        ]);

        $file = $request->file('file');
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_IMPORT_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => ['Unsupported file type. Please upload a .csv, .xlsx, or .ods file.'],
            ]);
        }

        $mapping = (array) $request->input('mapping', []);
        $path = $file->storeAs('imports', (string) Str::uuid().'.'.$extension);
        $fullPath = Storage::path($path);

        // Preflight: confirm the file is readable and has the required column
        // before queueing, so bad files fail with a clear message immediately.
        try {
            $reader = SimpleExcelReader::create($fullPath);
            $headers = $reader->getHeaders();
            $reader->close();
        } catch (Throwable $e) {
            Storage::delete($path);

            throw ValidationException::withMessages([
                'file' => ['The file could not be read. Please check it is a valid CSV, XLSX, or ODS file.'],
            ]);
        }

        $nameColumn = (string) ($mapping['name'] ?? 'name');

        if (! is_array($headers) || ! in_array($nameColumn, $headers, true)) {
            Storage::delete($path);

            throw ValidationException::withMessages([
                'file' => ["The file must have a header row with a '{$nameColumn}' column. Email and phone columns are optional."],
            ]);
        }

        $import = GuestImport::create([
            'event_id' => $event->id,
            'user_id' => $request->user()->id,
            'original_filename' => $file->getClientOriginalName(),
            'status' => 'queued',
        ]);

        AuditLogger::log($request, 'guests.import', $event, ['import_id' => $import->id]);

        dispatch(new ProcessGuestImportJob($import->id, $fullPath, $mapping));

        return response()->json(['import' => $this->importPayload($import->fresh())], 202);
    }

    public function importStatus(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $import = $event->imports()->latest('id')->first();

        return response()->json([
            'import' => $import ? $this->importPayload($import) : null,
        ]);
    }

    public function export(Request $request, Event $event)
    {
        $this->authorize('view', $event);

        // XLSX is a ZIP container and OpenSpout requires ZipArchive. Fail with a
        // clear response instead of a confusing error from the writer destructor
        // when the extension is unavailable.
        if (! extension_loaded('zip')) {
            return response()->json(['message' => 'XLSX export requires the PHP zip extension.'], 503);
        }

        // OpenSpout (via Spatie SimpleExcel) opens the target with fopen() and
        // throws an IOException when the parent directory is missing. Ensure the
        // export directory exists before the writer is created.
        Storage::makeDirectory('exports');

        $exportPath = 'exports/guests-'.$event->id.'-'.Str::uuid().'.xlsx';
        $fullPath = Storage::path($exportPath);

        $writer = SimpleExcelWriter::create($fullPath);

        $event->guests()
            ->with('rsvp')
            ->orderBy('id')
            ->chunk(500, function ($guests) use ($writer) {
                foreach ($guests as $guest) {
                    $writer->addRow([
                        'id' => $guest->id,
                        'name' => $guest->name,
                        'email' => $guest->email,
                        'phone' => $guest->phone,
                        'status' => $guest->status,
                        'sent_at' => optional($guest->sent_at)->toDateTimeString(),
                        'reminded_at' => optional($guest->reminded_at)->toDateTimeString(),
                        'attending_count' => $guest->rsvp?->attending_count,
                        'answers' => $guest->rsvp?->answers ? json_encode($guest->rsvp->answers) : null,
                    ]);
                }
            });

        $writer->close();

        AuditLogger::log($request, 'guests.export', $event);

        return response()->download($fullPath)->deleteFileAfterSend(true);
    }

    public function resend(Request $request, Event $event, Guest $guest): JsonResponse
    {
        $this->authorize('update', $event);
        $this->authorize('update', $guest);

        if ($guest->event_id !== $event->id) {
            abort(404);
        }

        $guest->sent_at = null;
        $guest->save();

        dispatch(new SendInvitationJob($guest));

        AuditLogger::log($request, 'guests.resend', $guest);

        return response()->json(['queued' => true], 202);
    }

    public function remind(Request $request, Event $event, Guest $guest): JsonResponse
    {
        $this->authorize('update', $event);

        if ($guest->event_id !== $event->id) {
            abort(404);
        }

        $this->authorize('update', $guest);

        if ($guest->status !== 'pending' || $guest->reminded_at !== null) {
            return response()->json(['message' => 'This guest is not eligible for a reminder.'], 422);
        }

        dispatch(new SendReminderJob($guest));

        AuditLogger::log($request, 'guests.remind', $guest);

        return response()->json(['queued' => true], 202);
    }

    public function sendReminders(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $cutoff = Carbon::now()->subDays(2);

        $guests = $event->guests()
            ->pending()
            ->unreminded()
            ->whereNotNull('sent_at')
            ->where('sent_at', '<=', $cutoff)
            ->get();

        foreach ($guests as $guest) {
            dispatch(new SendReminderJob($guest));
        }

        AuditLogger::log($request, 'guests.reminders.send', $event, ['queued' => $guests->count()]);

        return response()->json(['queued' => $guests->count()], 202);
    }

    /**
     * Report whether a guest's invitation is currently valid.
     *
     * Operator-only and account-scoped. This checks invitation validity only:
     * it does not record attendance or prove physical presence, and it never
     * returns the invitation token, contact information or other guests.
     */
    public function verification(Request $request, Event $event, Guest $guest): JsonResponse
    {
        $this->authorize('view', $event);

        if ($guest->event_id !== $event->id) {
            abort(404);
        }

        $this->authorize('view', $guest);

        $status = InvitationAccess::status($guest);

        return response()->json([
            'valid' => $status === InvitationAccess::AVAILABLE,
            'reason' => $status === InvitationAccess::AVAILABLE ? null : $status,
            'guest' => [
                'id' => $guest->id,
                'name' => $guest->name,
                'status' => $guest->status,
            ],
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'event_start' => $event->event_start?->toIso8601String(),
            ],
        ]);
    }

    private function importPayload(GuestImport $import): array
    {
        return [
            'id' => $import->id,
            'status' => $import->status,
            'original_filename' => $import->original_filename,
            'created_count' => $import->created_count,
            'skipped_count' => $import->skipped_count,
            'failed_count' => $import->failed_count,
            'error' => $import->error,
            'started_at' => $import->started_at?->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
        ];
    }
}
