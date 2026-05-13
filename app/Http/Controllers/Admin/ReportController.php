<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;

class ReportController extends Controller
{
    public function events(Request $request): JsonResponse
    {
        $user = $request->user();
        $ownerId = $user->isStaff() ? $user->owner_id : $user->id;

        $events = Event::query()
            ->where('user_id', $ownerId)
            ->withCount([
                'guests as guests_total',
                'guests as guests_responded' => fn ($q) => $q->whereIn('status', ['accepted', 'declined']),
            ])
            ->orderByDesc('event_start')
            ->get()
            ->map(function (Event $event) {
                $total = (int) $event->guests_total;
                $responded = (int) $event->guests_responded;
                $rate = $total === 0 ? 0.0 : round(($responded / $total) * 100, 2);

                return [
                    'id' => $event->id,
                    'title' => $event->title,
                    'event_start' => $event->event_start?->toDateTimeString(),
                    'response_rate' => $rate,
                    'guests_total' => $total,
                    'guests_responded' => $responded,
                ];
            });

        return response()->json([
            'total_events' => $events->count(),
            'events' => $events,
        ]);
    }

    public function export(Request $request, Event $event)
    {
        $this->authorize('view', $event);

        $exportPath = 'exports/report-'.$event->id.'-'.Str::uuid().'.xlsx';
        $fullPath = Storage::path($exportPath);

        $writer = SimpleExcelWriter::create($fullPath);

        $event->guests()
            ->with('rsvp')
            ->orderBy('id')
            ->chunk(500, function ($guests) use ($writer, $event) {
                foreach ($guests as $guest) {
                    $writer->addRow([
                        'event_id' => $event->id,
                        'event_title' => $event->title,
                        'guest_id' => $guest->id,
                        'guest_name' => $guest->name,
                        'guest_email' => $guest->email,
                        'guest_phone' => $guest->phone,
                        'guest_status' => $guest->status,
                        'attending_count' => $guest->rsvp?->attending_count,
                        'answers' => $guest->rsvp?->answers ? json_encode($guest->rsvp->answers) : null,
                    ]);
                }
            });

        $writer->close();

        AuditLogger::log($request, 'reports.export', $event);

        return response()->download($fullPath)->deleteFileAfterSend(true);
    }
}
