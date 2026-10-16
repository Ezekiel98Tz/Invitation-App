<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Event;
use App\Models\Pledge;
use App\Models\PledgeFulfillment;
use App\Models\PledgeImportBatch;
use App\Support\ContactNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * Spreadsheet importer for pledge information (information management only).
 *
 * A pledge is a *record* of what a client said they would give; an import never
 * moves, holds, verifies or settles money and never touches invitations, RSVPs,
 * credentials or check-in. Outstanding pledges never block any workflow.
 *
 * ---------------------------------------------------------------------------
 * Matching / update strategy (deliberately conservative)
 * ---------------------------------------------------------------------------
 * Rows are matched to an existing pledge in the same EVENT using the following
 * precedence. The first rule that yields a result wins:
 *
 *  1. Explicit identity — a mapped `guest_id` (event-scoped) or `contact_id`
 *     (account-scoped) column. This is authoritative: when supplied, only that
 *     identity is used.
 *  2. Resolved identity — the row's email/phone mapped (via the shared
 *     `ContactNormalizer`) to an existing event guest and/or account contact.
 *     Email and phone resolving to *different* identities (or several
 *     identities sharing a key) is treated as an identity conflict.
 *  3. Import signature — only when no identity was found AND the row carries a
 *     usable email or phone: an existing pledge in the event with the same
 *     normalized email/phone, the same currency and the exact same pledged
 *     amount.
 *
 * If rules 1–3 yield more than one candidate the row is marked `ambiguous`.
 * Ambiguous rows are NEVER merged silently: `confirm` skips them unless the
 * caller supplies an explicit per-row resolution decision.
 *
 * A name (even with an equal amount) is NEVER sufficient to match. Two
 * different pledgers can share a name and amount, so merging on name+amount
 * would silently corrupt unrelated records. Rows that carry no email, phone,
 * guest or contact therefore always create a new pledge, and a re-import of
 * anonymous rows cannot be de-duplicated — this is intentional and safer than
 * guessing (documented as a known limitation of the workflow).
 *
 * ---------------------------------------------------------------------------
 * Update vs. duplicate policy
 * ---------------------------------------------------------------------------
 * `mode` is explicit per request:
 *  - `create_only` (default): a row that matches an existing pledge is skipped
 *    (never updated, never duplicated).
 *  - `update_matched`: a row that matches exactly one pledge explicitly updates
 *    that pledge's reported information.
 * Re-importing the same file is therefore idempotent: matched rows are skipped
 * or updated in place, and fulfillments are de-duplicated by
 * (pledge + amount + received_on + reference).
 *
 * ---------------------------------------------------------------------------
 * Fulfillments
 * ---------------------------------------------------------------------------
 * Creating a pledge, updating a pledge and recording a fulfillment are three
 * distinct actions. A row may carry a reported fulfillment amount/date; a
 * fulfillment is only created when no identical recorded entry already exists.
 *
 * ---------------------------------------------------------------------------
 * Side effects
 * ---------------------------------------------------------------------------
 * The importer only READS guests and contacts — it never creates, edits or
 * deletes them. It creates/updates pledges (source = `import`) and appends
 * reported fulfillments. All pledge writes go through `PledgeLedger` so the
 * established business rules and per-pledge audit entries are reused.
 */
class PledgeImporter
{
    public const MODE_CREATE_ONLY = 'create_only';

    public const MODE_UPDATE_MATCHED = 'update_matched';

    /**
     * Currency used when a row (and the request) does not specify one. Documented
     * so the default is never a hidden surprise; every pledge still stores an
     * explicit code and mixed currencies are never summed together.
     */
    public const DEFAULT_CURRENCY = 'USD';

    /**
     * THE canonical import contract — the single authoritative definition of the
     * supported pledge import fields. Template generation
     * (`templateRow()`), header recognition (`suggestMapping()`/`recognition()`),
     * required-column validation (`unresolvedRequiredFields()`) and the README
     * all derive from this list, so the template and the importer can never
     * drift apart.
     *
     * `header` is the user-facing spreadsheet column (ordinary, human-readable:
     * amounts are ordinary numbers, never minor units). `key` is the internal
     * field consumed by `extractValues()`. `example` is written into the
     * downloadable template.
     *
     * @var list<array{key: string, header: string, label: string, required: bool, example: int|string}>
     */
    public const FIELDS = [
        ['key' => 'pledger_name', 'header' => 'pledger_name', 'label' => 'Pledger name', 'required' => true, 'example' => 'Acme Ltd'],
        ['key' => 'pledger_email', 'header' => 'email', 'label' => 'Email', 'required' => false, 'example' => 'acme@example.test'],
        ['key' => 'pledger_phone', 'header' => 'phone', 'label' => 'Phone', 'required' => false, 'example' => '+255700000010'],
        ['key' => 'pledged_amount', 'header' => 'amount', 'label' => 'Pledged amount', 'required' => true, 'example' => 500000],
        ['key' => 'contributed_amount', 'header' => 'contributed_amount', 'label' => 'Contributed amount', 'required' => false, 'example' => ''],
        ['key' => 'currency', 'header' => 'currency', 'label' => 'Currency', 'required' => false, 'example' => 'USD'],
        ['key' => 'method', 'header' => 'method', 'label' => 'Method', 'required' => false, 'example' => ''],
        ['key' => 'note', 'header' => 'notes', 'label' => 'Notes', 'required' => false, 'example' => 'Pledged at the site visit'],
        ['key' => 'reference', 'header' => 'reference', 'label' => 'Reference', 'required' => false, 'example' => ''],
        ['key' => 'guest_id', 'header' => 'guest_id', 'label' => 'Guest id', 'required' => false, 'example' => ''],
        ['key' => 'contact_id', 'header' => 'contact_id', 'label' => 'Contact id', 'required' => false, 'example' => ''],
        ['key' => 'received_on', 'header' => 'received_on', 'label' => 'Contribution date', 'required' => false, 'example' => ''],
    ];

    /**
     * Columns Invitation Studio knowingly does not import, with the operator-
     * facing reason. `pledge_date` is reported explicitly — it is never silently
     * dropped and never reinterpreted as a contribution date.
     *
     * @var array<string, string>
     */
    private const UNSUPPORTED_NOTES = [
        'pledge_date' => 'not imported — a pledge date is not supported. Use the received_on column for the date a contribution was received.',
    ];

    /**
     * Display labels for supported legacy keys that are not part of FIELDS.
     *
     * @var array<string, string>
     */
    private const LEGACY_LABELS = [
        'pledged_amount_minor' => 'Pledged amount (minor units — legacy)',
        'amount_minor' => 'Contributed amount (minor units — legacy)',
        'outstanding_balance' => 'Outstanding balance',
    ];

    /**
     * Canonical field => accepted (normalized) header aliases, in priority order.
     * A field claims the first not-yet-used column whose normalized header equals
     * one of its aliases. The canonical `header` of every FIELDS entry is listed
     * first so the official template always wins; the longer lists preserve the
     * existing compatibility contract for third-party/legacy spreadsheets.
     *
     * @var array<string, list<string>>
     */
    private const HEADER_ALIASES = [
        'pledger_name' => ['pledger_name', 'pledger', 'full_name', 'fullname', 'donor', 'donor_name', 'sponsor', 'sponsor_name', 'name'],
        'pledger_email' => ['email', 'pledger_email', 'email_address', 'e_mail', 'donor_email', 'sponsor_email'],
        'pledger_phone' => ['phone', 'pledger_phone', 'phone_number', 'mobile', 'mobile_number', 'msisdn', 'tel', 'donor_phone', 'sponsor_phone'],
        'pledged_amount' => ['amount', 'pledged_amount', 'pledge_amount', 'total_pledge', 'total_pledged', 'amount_pledged', 'pledged'],
        'contributed_amount' => ['contributed_amount', 'amount_contributed', 'contributed', 'contribution', 'paid', 'paid_amount', 'amount_paid'],
        'currency' => ['currency', 'currency_code', 'ccy'],
        'method' => ['method', 'payment_method', 'fulfillment_method'],
        'note' => ['notes', 'note', 'comments', 'comment', 'remarks'],
        'reference' => ['reference', 'ref', 'receipt', 'receipt_number', 'transaction_reference', 'txn', 'txn_ref'],
        'guest_id' => ['guest_id', 'guest'],
        'contact_id' => ['contact_id', 'contact'],
        'received_on' => ['received_on', 'contribution_date', 'received_date', 'fulfilled_on', 'fulfillment_date', 'paid_on', 'date'],
        'outstanding_balance' => ['outstanding_balance', 'outstanding', 'balance', 'balance_due'],
        'pledged_amount_minor' => ['pledged_amount_minor', 'pledge_amount_minor', 'pledged_minor', 'pledge_minor', 'amount_minor'],
        'amount_minor' => ['fulfillment_amount_minor', 'fulfilment_amount_minor', 'fulfilled_amount_minor', 'fulfillment_minor', 'received_amount_minor', 'paid_amount_minor', 'fulfilled_amount', 'fulfillment_amount', 'amount_received_minor'],
    ];

    public function __construct(private readonly PledgeLedger $ledger) {}

    /**
     * The official template's header row plus one illustrative example row,
     * derived from FIELDS so the template and the importer cannot drift apart.
     * Headers are ordinary, human-readable column names: the pledged amount is a
     * plain number (e.g. 500000), never a minor-unit value.
     *
     * @return array<string, int|string>
     */
    public function templateRow(): array
    {
        $row = [];

        foreach (self::FIELDS as $field) {
            $row[$field['header']] = $field['example'];
        }

        return $row;
    }

    /**
     * The canonical import contract (header/key/label/required/example).
     *
     * @return list<array{key: string, header: string, label: string, required: bool, example: int|string}>
     */
    public static function fields(): array
    {
        return self::FIELDS;
    }

    /**
     * Required canonical fields that the resolved mapping does NOT satisfy. The
     * pledged-amount requirement may also be met by the legacy
     * `pledged_amount_minor` column, which remains part of the compatibility
     * contract.
     *
     * @param  array<string, mixed>  $mapping
     * @return list<array{key: string, header: string, label: string, required: bool, example: int|string}>
     */
    public function unresolvedRequiredFields(array $mapping): array
    {
        $resolved = static fn (string $key): bool => isset($mapping[$key])
            && is_string($mapping[$key])
            && $mapping[$key] !== '';

        $missing = [];

        foreach (self::FIELDS as $field) {
            if (! $field['required']) {
                continue;
            }

            $satisfied = $resolved($field['key']);

            if (! $satisfied && $field['key'] === 'pledged_amount') {
                $satisfied = $resolved('pledged_amount_minor');
            }

            if (! $satisfied) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Describe how the detected columns map onto the canonical contract, so the
     * UI can report recognized columns, unsupported columns (with a reason) and
     * missing required headers before any row is processed.
     *
     * @param  list<string>  $columns
     * @return array{
     *     recognized: list<array{header: string, key: string, label: string, required: bool, legacy: bool}>,
     *     unsupported: list<string>,
     *     notices: list<array{header: string, reason: string}>,
     *     missing_required: list<array{header: string, label: string}>
     * }
     */
    public function recognition(array $columns): array
    {
        $mapping = $this->suggestMapping($columns);

        $definitions = [];
        foreach (self::FIELDS as $field) {
            $definitions[$field['key']] = $field;
        }

        $recognized = [];
        foreach ($mapping as $key => $header) {
            $definition = $definitions[$key] ?? null;

            $recognized[] = [
                'header' => $header,
                'key' => $key,
                'label' => $definition['label'] ?? (self::LEGACY_LABELS[$key] ?? (string) $key),
                'required' => (bool) ($definition['required'] ?? false),
                'legacy' => $definition === null,
            ];
        }

        $used = array_values($mapping);
        $unsupported = [];
        foreach ($columns as $column) {
            if (! in_array($column, $used, true)) {
                $unsupported[] = (string) $column;
            }
        }

        $notices = [];
        foreach ($unsupported as $header) {
            $notices[] = [
                'header' => $header,
                'reason' => self::UNSUPPORTED_NOTES[$this->normalizeHeader($header)]
                    ?? 'not a supported Invitation Studio column — it will be ignored',
            ];
        }

        $missing = [];
        foreach ($this->unresolvedRequiredFields($mapping) as $field) {
            $missing[] = ['header' => $field['header'], 'label' => $field['label']];
        }

        return [
            'recognized' => $recognized,
            'unsupported' => $unsupported,
            'notices' => $notices,
            'missing_required' => $missing,
        ];
    }

    /**
     * Project one raw row onto the canonical fields for a UI display sample.
     * Values are returned as read (never persisted), so the operator sees the
     * actual extracted cells before previewing.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $mapping
     * @return array<string, mixed>
     */
    public function sampleRow(array $raw, array $mapping): array
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            $values[$field['key']] = $this->cell($raw, $mapping, $field['key']);
        }

        if ($values['pledged_amount'] === null && $this->mapped($mapping, 'pledged_amount_minor')) {
            $values['pledged_amount'] = $this->cell($raw, $mapping, 'pledged_amount_minor');
        }

        return $values;
    }

    /**
     * Detected header columns for a spreadsheet.
     *
     * @return list<string>
     */
    public function inspect(string $fullPath): array
    {
        $reader = SimpleExcelReader::create($fullPath);

        try {
            $headers = (array) $reader->getHeaders();
        } finally {
            $reader->close();
        }

        $columns = [];

        foreach ($headers as $header) {
            if (! is_scalar($header)) {
                continue;
            }

            $value = trim((string) $header);

            if ($value !== '') {
                $columns[] = $value;
            }
        }

        return $columns;
    }

    /**
     * Suggest a column mapping from the detected columns.
     *
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    public function suggestMapping(array $columns): array
    {
        $normalized = [];
        foreach ($columns as $column) {
            $normalized[$column] = $this->normalizeHeader($column);
        }

        $used = [];
        $mapping = [];

        foreach (self::HEADER_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $matched = false;

                foreach ($normalized as $column => $normalizedColumn) {
                    if (in_array($column, $used, true)) {
                        continue;
                    }

                    if ($normalizedColumn === $alias) {
                        $mapping[$field] = $column;
                        $used[] = $column;
                        $matched = true;
                        break;
                    }
                }

                if ($matched) {
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Read every data row (header excluded) as an associative array.
     *
     * @return list<array<string, mixed>>
     */
    public function readRows(string $fullPath): array
    {
        $reader = SimpleExcelReader::create($fullPath);

        try {
            $rows = $reader->getRows()->toArray();
        } finally {
            $reader->close();
        }

        return array_values(array_map(
            static fn ($row) => is_array($row) ? $row : [],
            $rows
        ));
    }

    /**
     * Count the data rows that `process()` would consider, without materialising
     * the whole workbook. The header row is excluded by the reader and genuinely
     * blank rows are excluded using the same `isBlankRow()` rule the importer
     * applies when processing, so the count matches the rows that would be
     * validated.
     *
     * `getRows()` is a LazyCollection, so this streams the workbook. When
     * `$stopAfter` is supplied the read stops as soon as the count exceeds it,
     * so an over-limit file is rejected without loading every remaining row.
     *
     * @param  array<string, string>  $mapping
     */
    public function countDataRows(string $fullPath, array $mapping, ?int $stopAfter = null): int
    {
        $reader = SimpleExcelReader::create($fullPath);
        $count = 0;

        try {
            foreach ($reader->getRows() as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                if ($this->isBlankRow($raw, $mapping)) {
                    continue;
                }

                $count++;

                if ($stopAfter !== null && $count > $stopAfter) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        return $count;
    }

    /**
     * The first `$limit` data rows projected for display, read as a stream so a
     * workbook is never fully materialised just to show a sample. Row numbers are
     * the raw data-row positions (matching `process()`), with blank rows skipped.
     *
     * @param  array<string, string>  $mapping
     * @return list<array{row: int, values: array<string, mixed>}>
     */
    public function sampleRows(string $fullPath, array $mapping, int $limit = 3): array
    {
        $reader = SimpleExcelReader::create($fullPath);
        $sample = [];
        $position = 0;

        try {
            foreach ($reader->getRows() as $raw) {
                if ($position >= $limit) {
                    break;
                }

                if (! is_array($raw)) {
                    continue;
                }

                $position++;

                if ($this->isBlankRow($raw, $mapping)) {
                    continue;
                }

                $sample[] = ['row' => $position, 'values' => $this->sampleRow($raw, $mapping)];
            }
        } finally {
            $reader->close();
        }

        return $sample;
    }

    /**
     * Analyse rows without mutating anything. Returns the per-row proposal and
     * proposed counts. Used by the preview endpoint.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $mapping
     * @param  array<int|string, array<string, mixed>>  $decisions
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function preview(
        Event $event,
        array $rows,
        array $mapping,
        array $decisions,
        string $mode,
        ?string $defaultCurrency,
    ): array {
        return $this->process($event, $rows, $mapping, $decisions, $mode, $defaultCurrency, false, null, null);
    }

    /**
     * Apply the import inside a single transaction. Either every accepted row is
     * applied or none is: a failure rolls the whole batch back. The batch status
     * and summary are updated in the same transaction.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $mapping
     * @param  array<int|string, array<string, mixed>>  $decisions
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function confirm(
        Request $request,
        Event $event,
        PledgeImportBatch $batch,
        array $rows,
        array $mapping,
        array $decisions,
        string $mode,
        ?string $defaultCurrency,
    ): array {
        return DB::transaction(function () use ($request, $event, $batch, $rows, $mapping, $decisions, $mode, $defaultCurrency) {
            $batch->forceFill(['status' => PledgeImportBatch::STATUS_PROCESSING])->save();

            $result = $this->process($event, $rows, $mapping, $decisions, $mode, $defaultCurrency, true, $request, $batch);

            $batch->forceFill([
                'status' => PledgeImportBatch::STATUS_COMPLETED,
                'mapping' => $mapping,
                'summary' => array_merge((array) $batch->summary, [
                    'mode' => $mode,
                    'created' => $result['counts']['created'],
                    'updated' => $result['counts']['updated'],
                    'skipped' => $result['counts']['skipped'],
                    'failed' => $result['counts']['failed'],
                    'ambiguous' => $result['counts']['ambiguous'],
                    'fulfillments_created' => $result['counts']['fulfillments_created'],
                    'fulfillments_skipped' => $result['counts']['fulfillments_skipped'],
                    'total_rows' => $result['counts']['total_rows'],
                    'finished_at' => now()->toIso8601String(),
                ]),
            ])->save();

            return $result;
        });
    }

    /**
     * Shared analysis/apply loop.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $mapping
     * @param  array<int|string, array<string, mixed>>  $decisions
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    private function process(
        Event $event,
        array $rows,
        array $mapping,
        array $decisions,
        string $mode,
        ?string $defaultCurrency,
        bool $apply,
        ?Request $request,
        ?PledgeImportBatch $batch,
    ): array {
        $context = $this->buildContext($event);

        // Currency default precedence: request default → event setting → config
        // default. A row's explicit currency still overrides this (see
        // extractValues()).
        $eventCurrency = is_string($event->settings['currency'] ?? null) ? (string) $event->settings['currency'] : null;
        $currency = strtoupper(trim((string) ($defaultCurrency ?: $eventCurrency ?: config('invitations.pledge_default_currency', self::DEFAULT_CURRENCY))));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = self::DEFAULT_CURRENCY;
        }

        /** @var array<string, true> $seen */
        $seen = [];
        /** @var array<int, true> $consumed */
        $consumed = [];

        $counts = [
            'total_rows' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'ambiguous' => 0,
            'fulfillments_created' => 0,
            'fulfillments_skipped' => 0,
        ];

        $result = [];
        $rowNumber = 0;

        foreach ($rows as $raw) {
            $rowNumber++;

            if (! is_array($raw)) {
                $raw = [];
            }

            if ($this->isBlankRow($raw, $mapping)) {
                continue;
            }

            $item = $this->analyzeRow($event, $raw, $rowNumber, $mapping, $currency, $context);

            if ($item['action'] === 'ambiguous') {
                $counts['ambiguous']++;
            }

            $item = $this->finalizeAction($event, $item, $mode, $decisions[$rowNumber] ?? $decisions[(string) $rowNumber] ?? null, $seen, $consumed, $apply);

            if ($apply && ($item['action'] === 'create' || $item['action'] === 'update')) {
                $this->apply($request, $batch, $event, $item, $counts);
            }

            switch ($item['action']) {
                case 'create':
                    $counts['created']++;
                    break;
                case 'update':
                    $counts['updated']++;
                    break;
                case 'skip':
                    $counts['skipped']++;
                    break;
                case 'failed':
                    $counts['failed']++;
                    break;
            }

            $counts['total_rows']++;
            $result[] = $item;
        }

        return ['rows' => $result, 'counts' => $counts];
    }

    /**
     * Analyse one row: parse + validate values, resolve identity and propose an
     * action. Never mutates anything.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $mapping
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function analyzeRow(Event $event, array $raw, int $rowNumber, array $mapping, string $currency, array $context): array
    {
        $errors = [];
        $values = $this->extractValues($raw, $mapping, $currency, $errors);

        $item = [
            'row' => $rowNumber,
            'action' => 'create',
            'reason' => null,
            'errors' => $errors,
            'values' => $values,
            'guest_id' => null,
            'contact_id' => null,
            'pledge_id' => null,
            'identity_key' => null,
            'fulfillment' => $values['fulfillment'],
        ];

        if ($errors !== []) {
            $item['action'] = 'failed';
            $item['reason'] = 'The row is invalid.';

            return $item;
        }

        $identity = $this->resolveIdentity($event, $values, $context, $errors);

        if ($errors !== []) {
            $item['action'] = 'failed';
            $item['reason'] = 'The row references an unknown identity.';
            $item['errors'] = $errors;

            return $item;
        }

        $item['guest_id'] = $identity['guest_id'];
        $item['contact_id'] = $identity['contact_id'];
        $item['identity_key'] = $this->identityKey($identity, $values, $currency);

        $pledgeIds = $identity['pledge_ids'];

        // No identity-based match: fall back to the documented
        // email/phone + currency + exact-amount signature.
        if (! $identity['conflict'] && $pledgeIds === []) {
            $pledgeIds = $this->fallbackMatch($context, $values);
        }

        if ($identity['conflict'] || count($pledgeIds) > 1) {
            $item['action'] = 'ambiguous';
            $item['reason'] = 'More than one existing pledge or identity matches this row.';

            return $item;
        }

        if (count($pledgeIds) === 1) {
            $pledgeId = (int) $pledgeIds[0];

            if (($context['pledge_status'][$pledgeId] ?? null) === Pledge::STATUS_CANCELLED) {
                $item['action'] = 'skip';
                $item['reason'] = 'A matching pledge exists but is cancelled.';

                return $item;
            }

            $item['action'] = 'update';
            $item['pledge_id'] = $pledgeId;
            $item['reason'] = $identity['conflict']
                ? null
                : 'Matched an existing pledge.';
        }

        return $item;
    }

    /**
     * Apply the mode / explicit-decision policy to the proposed action.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $decision
     * @param  array<string, true>  $seen
     * @param  array<int, true>  $consumed
     * @return array<string, mixed>
     */
    private function finalizeAction(Event $event, array $item, string $mode, ?array $decision, array &$seen, array &$consumed, bool $apply): array
    {
        if ($item['action'] === 'failed') {
            return $item;
        }

        $requested = is_array($decision) ? ($decision['action'] ?? null) : null;

        if ($requested === 'skip') {
            $item['action'] = 'skip';
            $item['pledge_id'] = null;
            $item['reason'] = 'Skipped by explicit decision.';

            return $item;
        }

        if ($requested === 'update') {
            $requestedId = isset($decision['pledge_id']) ? (int) $decision['pledge_id'] : null;

            $exists = $requestedId !== null
                && Pledge::query()->where('event_id', $event->id)->whereKey($requestedId)->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'decisions' => ['Row '.$item['row'].' was asked to update a pledge that does not belong to this event.'],
                ]);
            }

            if (isset($consumed[$requestedId])) {
                $item['action'] = 'skip';
                $item['pledge_id'] = null;
                $item['reason'] = 'Duplicate of an earlier row in this import; skipped.';

                return $item;
            }

            $consumed[$requestedId] = true;
            $item['action'] = 'update';
            $item['pledge_id'] = $requestedId;
            $item['reason'] = 'Update chosen by explicit decision.';

            return $item;
        }

        if ($requested === 'create') {
            $item['action'] = 'create';
            $item['pledge_id'] = null;
            $item['reason'] = 'Create chosen by explicit decision.';

            if ($item['identity_key'] !== null) {
                $seen[$item['identity_key']] = true;
            }

            return $item;
        }

        if ($item['action'] === 'ambiguous') {
            if ($apply) {
                $item['action'] = 'skip';
                $item['reason'] = 'Ambiguous match requires an explicit resolution; skipped.';
            } else {
                // Preview keeps ambiguity visible so the operator knows a decision
                // is required before confirming.
                $item['reason'] = 'Ambiguous match requires an explicit resolution decision.';
            }

            return $item;
        }

        if ($item['action'] === 'update') {
            if ($mode !== self::MODE_UPDATE_MATCHED) {
                $item['action'] = 'skip';
                $item['pledge_id'] = null;
                $item['reason'] = 'A matching pledge already exists (create_only mode); skipped.';

                return $item;
            }

            $id = $item['pledge_id'];

            if ($id !== null && isset($consumed[$id])) {
                $item['action'] = 'skip';
                $item['pledge_id'] = null;
                $item['reason'] = 'Duplicate of an earlier row in this import; skipped.';

                return $item;
            }

            if ($id !== null) {
                $consumed[$id] = true;
            }

            return $item;
        }

        // create
        if ($item['identity_key'] !== null && isset($seen[$item['identity_key']])) {
            $item['action'] = 'skip';
            $item['reason'] = 'Duplicate of an earlier row in this import; skipped.';

            return $item;
        }

        if ($item['identity_key'] !== null) {
            $seen[$item['identity_key']] = true;
        }

        return $item;
    }

    /**
     * Perform the accepted action. Only ever called inside the confirm
     * transaction.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $counts
     */
    private function apply(?Request $request, ?PledgeImportBatch $batch, Event $event, array &$item, array &$counts): void
    {
        if ($request === null || $batch === null) {
            return;
        }

        $values = $item['values'];

        if ($item['action'] === 'create') {
            $pledge = $this->ledger->create($request, $event, [
                'guest_id' => $item['guest_id'],
                'contact_id' => $item['contact_id'],
                'pledger_name' => $values['pledger_name'],
                'pledger_email' => $values['pledger_email'],
                'pledger_phone' => $values['pledger_phone'],
                'currency' => $values['currency'],
                'pledged_amount_minor' => $values['pledged_amount_minor'],
                'note' => $values['note'],
                'source' => Pledge::SOURCE_IMPORT,
            ]);

            $pledge->import_batch_id = $batch->id;
            $pledge->save();

            $item['pledge_id'] = $pledge->id;
        } else {
            $pledge = Pledge::query()->where('event_id', $event->id)->find($item['pledge_id']);

            if (! $pledge) {
                throw ValidationException::withMessages([
                    'decisions' => ['Row '.$item['row'].': the pledge to update does not belong to this event.'],
                ]);
            }

            // The matched pledge's currency and ownership are deliberately not
            // changed by an import; the row's amount/name/contact details are.
            $this->ledger->update($request, $pledge, [
                'pledger_name' => $values['pledger_name'],
                'pledger_email' => $values['pledger_email'],
                'pledger_phone' => $values['pledger_phone'],
                'pledged_amount_minor' => $values['pledged_amount_minor'],
                'note' => $values['note'],
            ]);
        }

        if ($item['fulfillment'] !== null) {
            $this->recordFulfillment($request, $pledge, $item['fulfillment'], $counts);
        }
    }

    /**
     * Append a reported fulfillment unless an identical recorded entry already
     * exists. De-duplication key: pledge + amount + received_on + reference.
     *
     * @param  array<string, mixed>  $fulfillment
     * @param  array<string, int>  $counts
     */
    private function recordFulfillment(Request $request, Pledge $pledge, array $fulfillment, array &$counts): void
    {
        $amount = (int) $fulfillment['amount_minor'];
        $receivedOn = $fulfillment['received_on'];
        $reference = $fulfillment['reference'];

        $exists = PledgeFulfillment::query()
            ->where('pledge_id', $pledge->id)
            ->where('status', PledgeFulfillment::STATUS_RECORDED)
            ->whereNull('reverses_fulfillment_id')
            ->where('amount_minor', $amount)
            ->when(
                $receivedOn === null,
                fn ($q) => $q->whereNull('received_on'),
                fn ($q) => $q->whereDate('received_on', $receivedOn),
            )
            ->when(
                $reference === null,
                fn ($q) => $q->whereNull('reference'),
                fn ($q) => $q->where('reference', $reference),
            )
            ->exists();

        if ($exists) {
            $counts['fulfillments_skipped']++;

            return;
        }

        $this->ledger->recordFulfillment($request, $pledge, [
            'amount_minor' => $amount,
            'method' => $fulfillment['method'],
            'received_on' => $receivedOn,
            'reference' => $reference,
            'note' => $fulfillment['note'],
        ]);

        $counts['fulfillments_created']++;
    }

    /**
     * Parse and validate a row's canonical values.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $mapping
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private function extractValues(array $raw, array $mapping, string $currency, array &$errors): array
    {
        $name = $this->string($this->cell($raw, $mapping, 'pledger_name'));
        if ($name === null) {
            $errors['pledger_name'] = 'A pledger name is required.';
        }

        $email = $this->string($this->cell($raw, $mapping, 'pledger_email'));
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['pledger_email'] = 'The pledger email is not a valid email address.';
        }

        $phone = $this->string($this->cell($raw, $mapping, 'pledger_phone'));
        $note = $this->string($this->cell($raw, $mapping, 'note'));

        $currencyRaw = $this->string($this->cell($raw, $mapping, 'currency'));
        $rowCurrency = $currencyRaw === null ? $currency : strtoupper($currencyRaw);
        if (! preg_match('/^[A-Z]{3}$/', $rowCurrency)) {
            $errors['currency'] = 'The currency must be a three-letter ISO-4217 code (e.g. USD, TZS).';
        }

        // Ordinary amounts are the primary import contract (human-readable, with
        // optional thousands separators / currency prefixes). The legacy
        // `*_minor` columns remain supported for backwards compatibility.
        $ordinaryPledged = $this->mapped($mapping, 'pledged_amount');
        $pledgedRaw = $ordinaryPledged
            ? $this->cell($raw, $mapping, 'pledged_amount')
            : $this->cell($raw, $mapping, 'pledged_amount_minor');
        $pledged = $ordinaryPledged
            ? $this->parseAmount($pledgedRaw, $rowCurrency, true, $errors, 'pledged_amount')
            : $this->parseMinor($pledgedRaw, true, $errors, 'pledged_amount_minor');

        if ($this->string($pledgedRaw) === null) {
            // Key the required-amount error on the canonical `pledged_amount`
            // field unless a legacy `pledged_amount_minor` column was the one
            // explicitly mapped. This keeps preview errors aligned with the
            // field names the UI actually shows.
            $amountErrorKey = (! $ordinaryPledged && $this->mapped($mapping, 'pledged_amount_minor'))
                ? 'pledged_amount_minor'
                : 'pledged_amount';

            $errors[$amountErrorKey] = 'A pledged amount is required.';
        }

        $fulfillment = null;
        $ordinaryContributed = $this->mapped($mapping, 'contributed_amount');
        $fulfillmentRaw = $ordinaryContributed
            ? $this->cell($raw, $mapping, 'contributed_amount')
            : $this->cell($raw, $mapping, 'amount_minor');

        if ($this->string($fulfillmentRaw) !== null) {
            $amount = $ordinaryContributed
                ? $this->parseAmount($fulfillmentRaw, $rowCurrency, false, $errors, 'contributed_amount')
                : $this->parseMinor($fulfillmentRaw, false, $errors, 'amount_minor');
            $receivedOn = $this->parseDate($this->string($this->cell($raw, $mapping, 'received_on')), $errors, 'received_on');
            $reference = $this->string($this->cell($raw, $mapping, 'reference'));
            $method = $this->string($this->cell($raw, $mapping, 'method'));

            if ($method !== null && ! in_array($method, PledgeFulfillment::METHODS, true)) {
                $errors['method'] = 'The fulfillment method is not supported (cash, bank_transfer, mobile_money, cheque, other).';
            }

            $fulfillment = [
                'amount_minor' => $amount,
                'received_on' => $receivedOn,
                'reference' => $reference,
                'method' => $method,
                'note' => $note,
            ];
        }

        // An optional supplied outstanding balance is never stored; it is only a
        // consistency check against pledged − reported contributions.
        if ($this->mapped($mapping, 'outstanding_balance')) {
            $outstandingRaw = $this->cell($raw, $mapping, 'outstanding_balance');

            if ($this->string($outstandingRaw) !== null) {
                $supplied = $this->parseAmount($outstandingRaw, $rowCurrency, true, $errors, 'outstanding_balance');

                if ($supplied !== null) {
                    $contributed = $fulfillment['amount_minor'] ?? 0;

                    if ($pledged !== null && $supplied !== max(0, $pledged - $contributed)) {
                        $errors['outstanding_balance'] = 'The supplied outstanding balance does not match the pledged amount minus reported contributions.';
                    }
                }
            }
        }

        $guestId = $this->parseId($this->cell($raw, $mapping, 'guest_id'), $errors, 'guest_id');
        $contactId = $this->parseId($this->cell($raw, $mapping, 'contact_id'), $errors, 'contact_id');

        return [
            'pledger_name' => $name,
            'pledger_email' => $email,
            'pledger_phone' => $phone,
            'currency' => $rowCurrency,
            'pledged_amount_minor' => $pledged,
            'note' => $note,
            'guest_id' => $guestId,
            'contact_id' => $contactId,
            'fulfillment' => $fulfillment,
        ];
    }

    /**
     * Resolve the guest/contact identity for a row and the pledges it matches.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $context
     * @param  array<string, string>  $errors
     * @return array{guest_id: ?int, contact_id: ?int, guest_ids: list<int>, contact_ids: list<int>, pledge_ids: list<int>, conflict: bool}
     */
    private function resolveIdentity(Event $event, array $values, array $context, array &$errors): array
    {
        $emailKey = ContactNormalizer::email($values['pledger_email']);
        $phoneKey = ContactNormalizer::phone($values['pledger_phone']);

        $guestIds = [];
        $contactIds = [];
        $conflict = false;

        if ($values['guest_id'] !== null) {
            $guestId = (int) $values['guest_id'];
            if (! isset($context['guest_ids'][$guestId])) {
                $errors['guest_id'] = 'The guest_id does not belong to this event.';
            } else {
                $guestIds = [$guestId];
            }
        }

        if ($values['contact_id'] !== null) {
            $contactId = (int) $values['contact_id'];
            if (! isset($context['contact_ids'][$contactId])) {
                $errors['contact_id'] = 'The contact_id does not belong to this account.';
            } else {
                $contactIds = [$contactId];
            }
        }

        // Only infer identity when the row did not supply one explicitly.
        if ($values['guest_id'] === null && $values['contact_id'] === null) {
            $emailGuests = $emailKey !== null ? ($context['guests_by_email'][$emailKey] ?? []) : [];
            $phoneGuests = $phoneKey !== null ? ($context['guests_by_phone'][$phoneKey] ?? []) : [];
            $guestIds = array_values(array_unique(array_merge($emailGuests, $phoneGuests)));

            if ($emailGuests !== [] && $phoneGuests !== [] && array_intersect($emailGuests, $phoneGuests) === []) {
                $conflict = true;
            }

            $emailContact = $emailKey !== null ? ($context['contacts_by_email'][$emailKey] ?? null) : null;
            $phoneContact = $phoneKey !== null ? ($context['contacts_by_phone'][$phoneKey] ?? null) : null;
            $contactIds = array_values(array_unique(array_filter([$emailContact, $phoneContact])));

            if ($emailContact !== null && $phoneContact !== null && $emailContact !== $phoneContact) {
                $conflict = true;
            }
        }

        if (count($guestIds) > 1 || count($contactIds) > 1) {
            $conflict = true;
        }

        $pledgeIds = [];
        foreach ($guestIds as $guestId) {
            foreach (($context['pledges_by_guest'][$guestId] ?? []) as $pledgeId) {
                $pledgeIds[$pledgeId] = true;
            }
        }
        foreach ($contactIds as $contactId) {
            foreach (($context['pledges_by_contact'][$contactId] ?? []) as $pledgeId) {
                $pledgeIds[$pledgeId] = true;
            }
        }

        return [
            'guest_id' => $guestIds[0] ?? null,
            'contact_id' => $contactIds[0] ?? null,
            'guest_ids' => $guestIds,
            'contact_ids' => $contactIds,
            'pledge_ids' => array_map('intval', array_keys($pledgeIds)),
            'conflict' => $conflict,
        ];
    }

    /**
     * Match an existing event pledge by normalized email/phone + currency +
     * exact amount. Name alone is never used.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $values
     * @return list<int>
     */
    private function fallbackMatch(array $context, array $values): array
    {
        $emailKey = ContactNormalizer::email($values['pledger_email']);
        $phoneKey = ContactNormalizer::phone($values['pledger_phone']);

        if ($emailKey === null && $phoneKey === null) {
            return [];
        }

        // Gather only the pledges whose normalized email/phone matches the row.
        // The context is pre-indexed (see buildContext), so this is proportional
        // to the number of candidates rather than to every pledge in the event.
        $candidateIds = [];

        if ($emailKey !== null) {
            foreach ($context['pledges_by_email'][$emailKey] ?? [] as $id => $_) {
                $candidateIds[$id] = true;
            }
        }

        if ($phoneKey !== null) {
            foreach ($context['pledges_by_phone'][$phoneKey] ?? [] as $id => $_) {
                $candidateIds[$id] = true;
            }
        }

        $matches = [];

        foreach (array_keys($candidateIds) as $id) {
            $pledge = $context['pledges'][$id] ?? null;

            if ($pledge === null) {
                continue;
            }

            // Same rule as before: exact currency AND exact pledged amount.
            if ($pledge['currency'] !== $values['currency'] || $pledge['amount'] !== $values['pledged_amount_minor']) {
                continue;
            }

            $matches[$id] = true;
        }

        // Deterministic order. Only the count / single match is consumed, so this
        // matches the previous behaviour while being stable across runs.
        $ids = array_map('intval', array_keys($matches));
        sort($ids);

        return $ids;
    }

    /**
     * Build the per-event lookup context once per import (avoids N+1 queries).
     *
     * @return array<string, mixed>
     */
    private function buildContext(Event $event): array
    {
        $guestsByEmail = [];
        $guestsByPhone = [];
        $guestIds = [];

        foreach ($event->guests()->select('id', 'email', 'phone')->cursor() as $guest) {
            $guestIds[(int) $guest->id] = true;

            $email = ContactNormalizer::email($guest->email);
            if ($email !== null) {
                $guestsByEmail[$email][] = (int) $guest->id;
            }

            $phone = ContactNormalizer::phone($guest->phone);
            if ($phone !== null) {
                $guestsByPhone[$phone][] = (int) $guest->id;
            }
        }

        $contactsByEmail = [];
        $contactsByPhone = [];
        $contactIds = [];

        foreach (Contact::query()->where('user_id', $event->user_id)->select('id', 'normalized_email', 'normalized_phone')->cursor() as $contact) {
            $contactIds[(int) $contact->id] = true;

            if ($contact->normalized_email !== null && $contact->normalized_email !== '') {
                $contactsByEmail[$contact->normalized_email] = (int) $contact->id;
            }

            if ($contact->normalized_phone !== null && $contact->normalized_phone !== '') {
                $contactsByPhone[$contact->normalized_phone] = (int) $contact->id;
            }
        }

        $pledgesByGuest = [];
        $pledgesByContact = [];
        $pledgesByEmail = [];
        $pledgesByPhone = [];
        $pledges = [];
        $pledgeStatus = [];

        foreach (Pledge::query()
            ->where('event_id', $event->id)
            ->select('id', 'guest_id', 'contact_id', 'currency', 'pledged_amount_minor', 'pledger_email', 'pledger_phone', 'status')
            ->cursor() as $pledge) {
            $id = (int) $pledge->id;

            if ($pledge->guest_id !== null) {
                $pledgesByGuest[(int) $pledge->guest_id][] = $id;
            }

            if ($pledge->contact_id !== null) {
                $pledgesByContact[(int) $pledge->contact_id][] = $id;
            }

            // Index by normalized email/phone so fallback matching only visits the
            // pledges that can actually match the row (see fallbackMatch()).
            $emailKey = ContactNormalizer::email($pledge->pledger_email);
            if ($emailKey !== null) {
                $pledgesByEmail[$emailKey][$id] = true;
            }

            $phoneKey = ContactNormalizer::phone($pledge->pledger_phone);
            if ($phoneKey !== null) {
                $pledgesByPhone[$phoneKey][$id] = true;
            }

            $pledges[$id] = [
                'currency' => (string) $pledge->currency,
                'amount' => (int) $pledge->pledged_amount_minor,
            ];
            $pledgeStatus[$id] = (string) $pledge->status;
        }

        return [
            'guests_by_email' => $guestsByEmail,
            'guests_by_phone' => $guestsByPhone,
            'guest_ids' => $guestIds,
            'contacts_by_email' => $contactsByEmail,
            'contacts_by_phone' => $contactsByPhone,
            'contact_ids' => $contactIds,
            'pledges_by_guest' => $pledgesByGuest,
            'pledges_by_contact' => $pledgesByContact,
            'pledges_by_email' => $pledgesByEmail,
            'pledges_by_phone' => $pledgesByPhone,
            'pledges' => $pledges,
            'pledge_status' => $pledgeStatus,
        ];
    }

    /**
     * Stable identity key used for within-import duplicate suppression.
     *
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $values
     */
    private function identityKey(array $identity, array $values, string $currency): ?string
    {
        if (count($identity['pledge_ids']) === 1) {
            return 'pledge:'.$identity['pledge_ids'][0];
        }

        if ($identity['guest_id'] !== null) {
            return 'guest:'.$identity['guest_id'];
        }

        if ($identity['contact_id'] !== null) {
            return 'contact:'.$identity['contact_id'];
        }

        $emailKey = ContactNormalizer::email($values['pledger_email']);
        $phoneKey = ContactNormalizer::phone($values['pledger_phone']);

        if ($emailKey !== null || $phoneKey !== null) {
            return 'sig:'.$currency.'|'.(int) $values['pledged_amount_minor'].'|'.($emailKey ?? '').'|'.($phoneKey ?? '');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $mapping
     */
    private function isBlankRow(array $raw, array $mapping): bool
    {
        foreach (array_values(array_unique($mapping)) as $column) {
            if ($this->string($raw[$column] ?? null) !== null) {
                return false;
            }
        }

        // With no usable mapping, fall back to inspecting the raw row.
        if ($mapping === []) {
            foreach ($raw as $value) {
                if ($this->string($value) !== null) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $mapping
     */
    private function cell(array $raw, array $mapping, string $field): mixed
    {
        $column = $mapping[$field] ?? null;

        if (! is_string($column) || $column === '' || ! array_key_exists($column, $raw)) {
            return null;
        }

        return $raw[$column];
    }

    private function string(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function parseMinor(mixed $value, bool $allowZero, array &$errors, string $key): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_int($value)) {
            $amount = $value;
        } elseif (is_float($value)) {
            if (floor($value) !== $value) {
                $errors[$key] = 'The amount must be a whole number of minor units.';

                return null;
            }
            $amount = (int) $value;
        } elseif (is_string($value)) {
            $trimmed = trim($value);
            if (! preg_match('/^-?\d+$/', $trimmed)) {
                $errors[$key] = 'The amount must be a whole number of minor units.';

                return null;
            }
            $amount = (int) $trimmed;
        } else {
            $errors[$key] = 'The amount must be a whole number of minor units.';

            return null;
        }

        if ($amount < 0) {
            $errors[$key] = 'The amount cannot be negative.';

            return null;
        }

        if (! $allowZero && $amount < 1) {
            $errors[$key] = 'The fulfillment amount must be greater than zero.';

            return null;
        }

        return $amount;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function parseId(mixed $value, array &$errors, string $key): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value))) {
            return (int) trim($value);
        }

        $errors[$key] = 'The identifier must be a whole number.';

        return null;
    }

    /**
     * Strictly validate an ISO-style date (YYYY-MM-DD or YYYY/MM/DD).
     *
     * @param  array<string, string>  $errors
     */
    private function parseDate(?string $value, array &$errors, string $key): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $value, $matches)) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        $errors[$key] = 'The date must be a valid date in YYYY-MM-DD format.';

        return null;
    }

    private function mapped(array $mapping, string $field): bool
    {
        return isset($mapping[$field]) && is_string($mapping[$field]) && $mapping[$field] !== '';
    }

    /**
     * Minor-unit precision for a currency (used to convert ordinary amounts).
     * Defaults to 2; covers the common zero- and three-decimal currencies.
     */
    private function currencyMinorDigits(string $currency): int
    {
        $code = strtoupper($currency);

        if (in_array($code, ['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'XOF', 'XAF', 'GNF', 'PYG', 'RWF', 'UGX', 'DJF', 'KMF', 'VUV'], true)) {
            return 0;
        }

        if (in_array($code, ['BHD', 'KWD', 'OMR', 'TND', 'IQD', 'JOD', 'LYD'], true)) {
            return 3;
        }

        return 2;
    }

    /**
     * Parse an ordinary human-readable amount into integer minor units for the
     * given currency. Accepts integers, floats and strings with thousands
     * separators, decimal points and an optional leading currency code/symbol.
     * Never silently rounds meaningful sub-minor precision.
     *
     * @param  array<string, string>  $errors
     */
    private function parseAmount(mixed $value, string $currency, bool $allowZero, array &$errors, string $key): ?int
    {
        $scale = $this->currencyMinorDigits($currency);

        if (is_int($value)) {
            $negative = $value < 0;
            $whole = (string) abs($value);
            $fraction = '';
        } elseif (is_float($value)) {
            $negative = $value < 0;
            $formatted = sprintf('%.'.$scale.'F', abs($value));
            [$whole, $fraction] = array_pad(explode('.', $formatted, 2), 2, '');
        } elseif (is_string($value)) {
            $str = trim($value);
            $negative = str_starts_with($str, '-');
            $str = ltrim($str, '+-');
            $str = (string) preg_replace('/^[A-Za-z]{2,4}\s*/', '', $str);
            $str = (string) preg_replace('/^[^\d]+/u', '', $str);
            $str = str_replace([',', ' ', "\u{00A0}", "\u{202F}", "'"], '', $str);

            if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $str, $m)) {
                $errors[$key] = 'The amount is not a valid number.';
                return null;
            }

            $whole = $m[1];
            $fraction = $m[2] ?? '';

            if (strlen($fraction) > $scale) {
                if ((int) substr($fraction, $scale) !== 0) {
                    $errors[$key] = 'The amount has more decimal places than '.$currency.' supports.';
                    return null;
                }
                $fraction = substr($fraction, 0, $scale);
            }
        } else {
            $errors[$key] = 'The amount is not a valid number.';
            return null;
        }

        if (strlen($whole) > 15) {
            $errors[$key] = 'The amount is too large.';
            return null;
        }

        $fraction = str_pad($fraction, $scale, '0');
        $minor = ((int) $whole) * (10 ** $scale) + ($scale > 0 ? (int) $fraction : 0);

        if ($negative && $minor !== 0) {
            $errors[$key] = 'The amount cannot be negative.';
            return null;
        }

        if (! $allowZero && $minor < 1) {
            $errors[$key] = 'The amount must be greater than zero.';
            return null;
        }

        return $minor;
    }

    private function normalizeHeader(string $header): string
    {
        $normalized = strtolower(trim($header));
        $normalized = (string) preg_replace('/[^a-z0-9]+/', '_', $normalized);

        return trim($normalized, '_');
    }
}
