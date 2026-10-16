<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Pledge;
use App\Models\PledgeImportBatch;
use App\Services\PledgeImporter;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * First-class pledge spreadsheet import/export workflow (information only).
 *
 * This controller stores an uploaded spreadsheet, previews a mapping, applies it
 * in an all-or-nothing transaction and exports pledges with their reported
 * fulfillments. It never moves, holds, verifies or settles money and never
 * touches invitations, RSVPs, credentials or check-in.
 *
 * Authorization mirrors PledgeController: event `view` for downloads, event
 * `update` for writes, and the batch policy for batch-scoped operations. Files
 * are stored on the private disk under `pledge-imports/{batchId}.{ext}` so the
 * batch itself is the lineage handle.
 */
class PledgeImportController extends Controller
{
    private const ALLOWED_IMPORT_EXTENSIONS = ['csv', 'xlsx', 'ods'];

    /**
     * Uploaded pledge source files live on the private `local` disk
     * (`storage/app/private/pledge-imports/{batchId}.{ext}`). The disk is
     * `visibility = private`, so Laravel's `/storage/{path}` route serves them
     * only through a signed URL, and the application never generates one for
     * these files: they are therefore not publicly served, and the `public`
     * disk / storage symlink does not expose them.
     *
     * Retention (recommended policy — no automated cleanup is installed here):
     * keep each source file for the audit window required by the account (a
     * reasonable default is 90 days after the batch reaches `completed`, or
     * immediately once its pledges and report are no longer needed), then purge
     * it with an explicit, logged operator action. Purge by batch id under this
     * directory only; never delete pledge/fulfillment rows (lineage is already
     * nullable and informational). Deleting a source file only makes the batch's
     * preview/errors/confirm re-runs report "upload again" — it never affects
     * recorded pledges.
     */
    private const IMPORT_DIRECTORY = 'pledge-imports';

    private const EXPORT_DIRECTORY = 'exports';

    /**
     * @var list<string>
     */
    private const ERROR_REPORT_COLUMNS = [
        'row',
        'action',
        'reason',
        'errors',
        'pledger_name',
        'pledger_email',
        'pledger_phone',
        'currency',
        'pledged_amount_minor',
        'fulfillment_amount_minor',
        'received_on',
        'reference',
    ];

    public function __construct(private readonly PledgeImporter $importer) {}

    /**
     * Store an uploaded pledge spreadsheet and return detected columns + a
     * suggested mapping. Creates a batch row only — no pledge is touched.
     */
    public function upload(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ]);

        $file = $request->file('file');
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_IMPORT_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => ['Unsupported file type. Please upload a .csv, .xlsx, or .ods file.'],
            ]);
        }

        $temporaryPath = $file->storeAs(self::IMPORT_DIRECTORY, (string) Str::uuid().'.'.$extension);

        try {
            $columns = $this->importer->inspect(Storage::path($temporaryPath));
        } catch (Throwable $e) {
            Storage::delete($temporaryPath);

            throw ValidationException::withMessages([
                'file' => ['The file could not be read. Please check it is a valid CSV, XLSX, or ODS file.'],
            ]);
        }

        if ($columns === []) {
            Storage::delete($temporaryPath);

            throw ValidationException::withMessages([
                'file' => ['The file must have a header row with at least one column.'],
            ]);
        }

        $mapping = $this->importer->suggestMapping($columns);

        $batch = PledgeImportBatch::create([
            'user_id' => $event->user_id,
            'event_id' => $event->id,
            'original_filename' => $file->getClientOriginalName(),
            'status' => PledgeImportBatch::STATUS_UPLOADED,
            'columns' => $columns,
            'mapping' => $mapping,
            'created_by' => $request->user()?->id,
        ]);

        // Persist the source at a deterministic, batch-scoped path for lineage.
        $storedPath = self::IMPORT_DIRECTORY.'/'.$batch->id.'.'.$extension;

        if (! Storage::move($temporaryPath, $storedPath) || ! Storage::exists($storedPath)) {
            // Fall back to copy+delete for filesystems where an atomic move is
            // unavailable, so the deterministic path always exists.
            Storage::copy($temporaryPath, $storedPath);
            Storage::delete($temporaryPath);
        }

        try {
            $rows = $this->importer->readRows(Storage::path($storedPath));
        } catch (Throwable) {
            $rows = [];
        }

        // A small, read-only sample of the actual extracted cells so the operator
        // can confirm recognition before previewing. Nothing is stored.
        $sample = [];
        foreach (array_slice($rows, 0, 3) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $sample[] = [
                'row' => $index + 1,
                'values' => $this->importer->sampleRow($row, $mapping),
            ];
        }

        AuditLogger::log($request, 'pledge_import.upload', $batch, [
            'event_id' => $event->id,
            'columns' => $columns,
        ]);

        return response()->json([
            'batch' => $this->batchPayload($batch->fresh()),
            'columns' => $columns,
            'mapping' => $mapping,
            'row_count' => count($rows),
            'recognition' => $this->importer->recognition($columns),
            'sample' => $sample,
        ], 201);
    }

    /**
     * Batch status and summary.
     */
    public function show(Request $request, Event $event, PledgeImportBatch $batch): JsonResponse
    {
        $this->authorize('view', $event);
        $this->ensureBatchBelongsToEvent($event, $batch);
        $this->authorize('view', $batch);

        return response()->json(['batch' => $this->batchPayload($batch)]);
    }

    /**
     * Row-level preview. Never mutates pledges.
     */
    public function preview(Request $request, Event $event, PledgeImportBatch $batch): JsonResponse
    {
        $this->authorize('update', $event);
        $this->ensureBatchBelongsToEvent($event, $batch);
        $this->authorize('update', $batch);

        $data = $request->validate([
            'mapping' => ['nullable', 'array'],
            'decisions' => ['nullable', 'array'],
            'mode' => ['nullable', 'string', 'in:create_only,update_matched'],
            'default_currency' => ['nullable', 'string', 'size:3'],
        ]);

        $mapping = $this->resolveMapping($batch, $data['mapping'] ?? null);
        $mode = $data['mode'] ?? PledgeImporter::MODE_CREATE_ONLY;

        $this->assertRequiredHeadersResolved($mapping);

        $result = $this->importer->preview(
            $event,
            $this->readSourceRows($batch),
            $mapping,
            (array) ($data['decisions'] ?? []),
            $mode,
            $data['default_currency'] ?? null,
        );

        $batch->forceFill([
            'status' => PledgeImportBatch::STATUS_PREVIEWED,
            'mapping' => $mapping,
            'summary' => array_merge((array) $batch->summary, [
                'last_preview' => $result['counts'],
            ]),
        ])->save();

        AuditLogger::log($request, 'pledge_import.preview', $batch, [
            'event_id' => $event->id,
            'counts' => $result['counts'],
        ]);

        return response()->json([
            'batch' => $this->batchPayload($batch->fresh()),
            'mapping' => $mapping,
            'mode' => $mode,
            'counts' => $result['counts'],
            'rows' => array_map(fn (array $row) => $this->rowPayload($row), $result['rows']),
        ]);
    }

    /**
     * Apply the import (all-or-nothing) and record the batch summary.
     */
    public function confirm(Request $request, Event $event, PledgeImportBatch $batch): JsonResponse
    {
        $this->authorize('update', $event);
        $this->ensureBatchBelongsToEvent($event, $batch);
        $this->authorize('update', $batch);

        $data = $request->validate([
            'mapping' => ['nullable', 'array'],
            'decisions' => ['nullable', 'array'],
            'mode' => ['nullable', 'string', 'in:create_only,update_matched'],
            'default_currency' => ['nullable', 'string', 'size:3'],
        ]);

        $mapping = $this->resolveMapping($batch, $data['mapping'] ?? null);
        $mode = $data['mode'] ?? PledgeImporter::MODE_CREATE_ONLY;

        $this->assertRequiredHeadersResolved($mapping);

        $result = $this->importer->confirm(
            $request,
            $event,
            $batch,
            $this->readSourceRows($batch),
            $mapping,
            (array) ($data['decisions'] ?? []),
            $mode,
            $data['default_currency'] ?? null,
        );

        AuditLogger::log($request, 'pledge_import.confirm', $batch, [
            'event_id' => $event->id,
            'mode' => $mode,
            'counts' => $result['counts'],
        ]);

        return response()->json([
            'batch' => $this->batchPayload($batch->fresh()),
            'counts' => $result['counts'],
            'rows' => array_map(fn (array $row) => $this->rowPayload($row), $result['rows']),
        ]);
    }

    /**
     * Downloadable per-row error report (invalid and ambiguous rows).
     */
    public function errors(Request $request, Event $event, PledgeImportBatch $batch): BinaryFileResponse|JsonResponse
    {
        $this->authorize('view', $event);
        $this->ensureBatchBelongsToEvent($event, $batch);
        $this->authorize('view', $batch);

        $format = strtolower($request->string('format')->toString());
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            $format = 'csv';
        }

        if ($format === 'xlsx' && ! extension_loaded('zip')) {
            return response()->json(['message' => 'XLSX export requires the PHP zip extension.'], 503);
        }

        $result = $this->importer->preview(
            $event,
            $this->readSourceRows($batch),
            $this->resolveMapping($batch, null),
            [],
            PledgeImporter::MODE_CREATE_ONLY,
            null,
        );

        $problemRows = array_values(array_filter(
            $result['rows'],
            static fn (array $row) => in_array($row['action'], ['failed', 'ambiguous'], true),
        ));

        Storage::makeDirectory(self::EXPORT_DIRECTORY);
        $exportPath = self::EXPORT_DIRECTORY.'/pledge-import-errors-'.$batch->id.'-'.Str::uuid().'.'.$format;
        $fullPath = Storage::path($exportPath);

        $writer = SimpleExcelWriter::create($fullPath);
        $writer->addHeader(self::ERROR_REPORT_COLUMNS);

        foreach ($problemRows as $row) {
            $writer->addRow($this->errorReportRow($row));
        }

        $writer->close();

        AuditLogger::log($request, 'pledge_import.errors', $batch, [
            'event_id' => $event->id,
            'rows' => count($problemRows),
        ]);

        return response()->download($fullPath)->deleteFileAfterSend(true);
    }

    /**
     * Downloadable XLSX template with the canonical columns.
     */
    public function template(Request $request, Event $event): BinaryFileResponse|JsonResponse
    {
        $this->authorize('view', $event);

        if (! extension_loaded('zip')) {
            return response()->json(['message' => 'XLSX export requires the PHP zip extension.'], 503);
        }

        Storage::makeDirectory(self::EXPORT_DIRECTORY);

        $exportPath = self::EXPORT_DIRECTORY.'/pledge-import-template-'.Str::uuid().'.xlsx';
        $fullPath = Storage::path($exportPath);

        $writer = SimpleExcelWriter::create($fullPath);
        $writer->addRow($this->importer->templateRow());
        $writer->close();

        AuditLogger::log($request, 'pledge_import.template', $event);

        return response()->download($fullPath)->deleteFileAfterSend(true);
    }

    /**
     * XLSX export of the event's pledges with their reported fulfillment.
     */
    public function export(Request $request, Event $event): BinaryFileResponse|JsonResponse
    {
        $this->authorize('view', $event);

        if (! extension_loaded('zip')) {
            return response()->json(['message' => 'XLSX export requires the PHP zip extension.'], 503);
        }

        Storage::makeDirectory(self::EXPORT_DIRECTORY);

        $exportPath = self::EXPORT_DIRECTORY.'/pledges-'.$event->id.'-'.Str::uuid().'.xlsx';
        $fullPath = Storage::path($exportPath);

        $writer = SimpleExcelWriter::create($fullPath);

        Pledge::query()
            ->where('event_id', $event->id)
            ->with('fulfillments')
            ->orderBy('id')
            ->chunk(500, function ($pledges) use ($writer, $event) {
                foreach ($pledges as $pledge) {
                    $writer->addRow([
                        'pledge_id' => $pledge->id,
                        'event_id' => $event->id,
                        'guest_id' => $pledge->guest_id,
                        'contact_id' => $pledge->contact_id,
                        'pledger_name' => $pledge->pledger_name,
                        'pledger_email' => $pledge->pledger_email,
                        'pledger_phone' => $pledge->pledger_phone,
                        'currency' => $pledge->currency,
                        'pledged_amount_minor' => (int) $pledge->pledged_amount_minor,
                        'fulfilled_minor' => $pledge->fulfilledMinor(),
                        'outstanding_minor' => $pledge->outstandingMinor(),
                        'derived_status' => $pledge->derivedStatus(),
                        'status' => $pledge->status,
                        'source' => $pledge->source,
                        'import_batch_id' => $pledge->import_batch_id,
                        'note' => $pledge->note,
                        'created_by' => $pledge->created_by,
                        'created_at' => optional($pledge->created_at)->toDateTimeString(),
                        'fulfillment_count' => $pledge->fulfillments->count(),
                        'fulfillments' => $pledge->fulfillments
                            ->map(fn ($fulfillment) => implode('|', [
                                (int) $fulfillment->amount_minor,
                                optional($fulfillment->received_on)->toDateString() ?? '',
                                (string) ($fulfillment->reference ?? ''),
                                (string) ($fulfillment->method ?? ''),
                                (string) $fulfillment->status,
                            ]))
                            ->implode('; '),
                    ]);
                }
            });

        $writer->close();

        AuditLogger::log($request, 'pledge_import.export', $event);

        return response()->download($fullPath)->deleteFileAfterSend(true);
    }

    /**
     * Resolve the stored source file for a batch, or abort with a clear message.
     *
     * @return list<array<string, mixed>>
     */
    private function readSourceRows(PledgeImportBatch $batch): array
    {
        $storedPath = $this->sourcePath($batch);

        if ($storedPath === null) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file is no longer available. Please upload it again.'],
            ]);
        }

        return $this->importer->readRows(Storage::path($storedPath));
    }

    private function sourcePath(PledgeImportBatch $batch): ?string
    {
        // Prefer the deterministic batch-scoped path (id + the uploaded file's
        // extension). A prefix scan alone can match a stale file that shares the
        // id prefix but a different extension (e.g. a leftover `1.csv` shadowing
        // this batch's `1.xlsx`).
        $extension = strtolower(pathinfo((string) $batch->original_filename, PATHINFO_EXTENSION));

        if ($extension !== '') {
            $candidate = self::IMPORT_DIRECTORY.'/'.$batch->id.'.'.$extension;

            if (Storage::exists($candidate)) {
                return $candidate;
            }
        }

        $prefix = $batch->id.'.';

        foreach (Storage::files(self::IMPORT_DIRECTORY) as $path) {
            if (str_starts_with(basename($path), $prefix)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $mapping
     * @return array<string, string>
     */
    private function resolveMapping(PledgeImportBatch $batch, ?array $mapping): array
    {
        // An EMPTY mapping array means "no explicit choices", so fall back to the
        // mapping stored at upload time (the suggested mapping). `??` alone would
        // treat `[]` as an explicit mapping and drop every field — which made
        // rows report missing pledger_name/pledged_amount.
        $resolved = (is_array($mapping) && $mapping !== [])
            ? $mapping
            : (array) ($batch->mapping ?? []);

        return array_filter(
            $resolved,
            static fn ($value) => is_string($value) && $value !== '',
        );
    }

    /**
     * File-level required-header validation. The real problem is a missing or
     * unrecognized COLUMN, so it is reported once as a file-level error rather
     * than as a missing value on every row.
     *
     * @param  array<string, string>  $mapping
     *
     * @throws ValidationException
     */
    private function assertRequiredHeadersResolved(array $mapping): void
    {
        $missing = $this->importer->unresolvedRequiredFields($mapping);

        if ($missing === []) {
            return;
        }

        $labels = array_map(
            static fn (array $field): string => $field['label'].' ('.$field['header'].')',
            $missing,
        );

        throw ValidationException::withMessages([
            'file' => [
                'This file is missing required column(s): '.implode(', ', $labels).
                '. Add the column(s) and upload again, or map them manually under “Advanced”.',
            ],
        ]);
    }

    private function ensureBatchBelongsToEvent(Event $event, PledgeImportBatch $batch): void
    {
        if ((int) $batch->event_id !== (int) $event->id) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function batchPayload(PledgeImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'event_id' => $batch->event_id,
            'status' => $batch->status,
            'original_filename' => $batch->original_filename,
            'columns' => $batch->columns,
            'mapping' => $batch->mapping,
            'summary' => $batch->summary,
            'created_by' => $batch->created_by,
            'created_at' => $batch->created_at?->toIso8601String(),
            'updated_at' => $batch->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rowPayload(array $row): array
    {
        $values = $row['values'] ?? [];

        return [
            'row' => $row['row'],
            'action' => $row['action'],
            'reason' => $row['reason'],
            'errors' => $row['errors'],
            'pledger_name' => $values['pledger_name'] ?? null,
            'pledger_email' => $values['pledger_email'] ?? null,
            'pledger_phone' => $values['pledger_phone'] ?? null,
            'currency' => $values['currency'] ?? null,
            'pledged_amount_minor' => $values['pledged_amount_minor'] ?? null,
            'guest_id' => $row['guest_id'],
            'contact_id' => $row['contact_id'],
            'pledge_id' => $row['pledge_id'],
            'fulfillment' => $row['fulfillment'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function errorReportRow(array $row): array
    {
        $values = $row['values'] ?? [];
        $errors = (array) ($row['errors'] ?? []);

        $errorText = implode('; ', array_map(
            static fn ($field, $message) => $field.': '.$message,
            array_keys($errors),
            array_values($errors),
        ));

        $fulfillment = $row['fulfillment'] ?? [];

        return [
            'row' => $row['row'],
            'action' => $row['action'],
            'reason' => $row['reason'],
            'errors' => $errorText,
            'pledger_name' => $values['pledger_name'] ?? null,
            'pledger_email' => $values['pledger_email'] ?? null,
            'pledger_phone' => $values['pledger_phone'] ?? null,
            'currency' => $values['currency'] ?? null,
            'pledged_amount_minor' => $values['pledged_amount_minor'] ?? null,
            'fulfillment_amount_minor' => $fulfillment['amount_minor'] ?? null,
            'received_on' => $fulfillment['received_on'] ?? null,
            'reference' => $fulfillment['reference'] ?? null,
        ];
    }
}
