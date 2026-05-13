<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGuestImportJob;
use App\Jobs\SendInvitationJob;
use App\Jobs\SendReminderJob;
use App\Models\Event;
use App\Models\Guest;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;

class GuestController extends Controller
{
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

        return response()->json($query->paginate(20));
    }

    public function import(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'file' => ['required', 'file'],
            'mapping' => ['nullable', 'array'],
        ]);

        $file = $data['file'];
        $path = $file->storeAs('imports', (string) Str::uuid().'.'.$file->getClientOriginalExtension());

        dispatch(new ProcessGuestImportJob(Storage::path($path), $event->id, $data['mapping'] ?? []));

        AuditLogger::log($request, 'guests.import', $event, ['stored_path' => $path]);

        return response()->json(['queued' => true], 202);
    }

    public function export(Request $request, Event $event)
    {
        $this->authorize('view', $event);

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
                        'invite_token' => $guest->invite_token,
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
}
