import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, apiErrorMessage, validationErrors } from '../lib/api';
import type {
    EventItem,
    EventPledgeSummary,
    PledgeImportBatch,
    PledgeImportConfirmResponse,
    PledgeImportCounts,
    PledgeImportDecision,
    PledgeImportMapping,
    PledgeImportMode,
    PledgeImportPreviewResponse,
    PledgeImportRecognition,
    PledgeImportRow,
    PledgeImportSampleRow,
    PledgeImportUploadResponse,
} from '../lib/types';
import { formatMoney } from '../lib/money';
import { Alert, LoadingBlock } from '../components/ui';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { FeatureDisabled } from '../components/FeatureDisabled';
import { isCapabilityEnabled } from '../lib/capabilities';
import { describeDownloadError, downloadFile } from '../lib/download';

const IMPORT_MAX_MB = 5;
const IMPORT_EXTENSIONS = ['csv', 'xlsx', 'ods'];
const FALLBACK_CURRENCY = 'USD';

interface MappingField {
    key: string;
    label: string;
    help: string;
    required?: boolean;
}

/**
 * Canonical import fields and the backend aliases that suggest them. The keys
 * are the exact field names `PledgeImporter` accepts in a mapping; the labels
 * keep the amount fields clearly distinguishable (a pledged amount vs. a
 * reported fulfillment amount).
 */
const MAPPING_FIELDS: MappingField[] = [
    { key: 'pledger_name', label: 'Pledger name', help: 'The person or organisation making the pledge. Required.', required: true },
    { key: 'pledger_email', label: 'Email address', help: 'Optional. Used with phone to match an existing guest or contact.' },
    { key: 'pledger_phone', label: 'Phone number', help: 'Optional. Used with email to match an existing guest or contact.' },
    {
        key: 'pledged_amount',
        label: 'Pledged amount',
        help: 'The total pledged (e.g. 150000, 150,000 or 150000.00). Required.',
        required: true,
    },
    { key: 'currency', label: 'Currency', help: 'Optional ISO-4217 code. If omitted, the default currency below is used.' },
    {
        key: 'contributed_amount',
        label: 'Amount already contributed',
        help: 'Optional reported amount received so far; recorded as a fulfillment entry.',
    },
    { key: 'outstanding_balance', label: 'Outstanding balance', help: 'Optional. Used only to check pledged minus contributed; never stored.' },
    { key: 'guest_id', label: 'Guest id', help: 'Optional explicit event-guest identity; takes precedence over email/phone.' },
    { key: 'contact_id', label: 'Contact id', help: 'Optional explicit account-contact identity; takes precedence over email/phone.' },
    { key: 'received_on', label: 'Contribution date', help: 'Optional date of the reported contribution (YYYY-MM-DD).' },
    { key: 'reference', label: 'Reference', help: 'Optional receipt or transaction reference.' },
    { key: 'method', label: 'Method', help: 'Optional: cash, bank_transfer, mobile_money, cheque, or other.' },
    { key: 'note', label: 'Note', help: 'Optional free-text note stored on the pledge.' },
];

type RowChoice = 'create' | 'update' | 'skip' | '';

const ACTION_META: Record<string, { label: string; className: string }> = {
    create: { label: 'Create', className: 'import-create' },
    update: { label: 'Update', className: 'import-update' },
    skip: { label: 'Skip', className: 'import-skip' },
    ambiguous: { label: 'Needs decision', className: 'import-ambiguous' },
    failed: { label: 'Invalid', className: 'import-failed' },
};

function actionMeta(action: string): { label: string; className: string } {
    return ACTION_META[action] ?? { label: action, className: 'import-skip' };
}

/** The action a row will take if the operator leaves it untouched. */
function proposedAction(row: PledgeImportRow): RowChoice {
    if (row.action === 'create') return 'create';
    if (row.action === 'update') return 'update';
    if (row.action === 'skip') return 'skip';
    return '';
}

function proposalLabel(action: RowChoice): string {
    switch (action) {
        case 'create':
            return 'create a new pledge';
        case 'update':
            return 'update the matched pledge';
        case 'skip':
            return 'skip this row';
        default:
            return 'no proposal — a decision is required';
    }
}

/** Order-independent signature of everything a preview depends on. */
function signatureOf(mapping: PledgeImportMapping, mode: PledgeImportMode, currency: string): string {
    const entries = Object.entries(mapping)
        .filter(([, column]) => column !== '')
        .sort(([a], [b]) => a.localeCompare(b));
    return JSON.stringify({ mapping: entries, mode, currency });
}

function errorList(row: PledgeImportRow): string[] {
    return Object.entries(row.errors ?? {}).map(([field, message]) => `${field}: ${message}`);
}

/** Render a sample cell value for display; blank/null reads as an em dash. */
function asText(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    return String(value);
}

export function PledgesImportPage() {
    const { id } = useParams<{ id: string }>();
    const eventId = id ?? '';

    const [event, setEvent] = useState<EventItem | null>(null);
    const [eventLoading, setEventLoading] = useState(true);
    const [loadError, setLoadError] = useState<string | null>(null);

    const [defaultCurrency, setDefaultCurrency] = useState(FALLBACK_CURRENCY);

    // Upload.
    const [file, setFile] = useState<File | null>(null);
    const [fileError, setFileError] = useState<string | null>(null);
    const [uploading, setUploading] = useState(false);
    const [batch, setBatch] = useState<PledgeImportBatch | null>(null);
    const [columns, setColumns] = useState<string[]>([]);
    const [mapping, setMapping] = useState<PledgeImportMapping>({});
    const [recognition, setRecognition] = useState<PledgeImportRecognition | null>(null);
    const [rowCount, setRowCount] = useState<number | null>(null);
    const [sample, setSample] = useState<PledgeImportSampleRow[]>([]);
    const fileInputRef = useRef<HTMLInputElement>(null);
    // Monotonic sequence so an out-of-order preview reply can never overwrite a
    // newer one (the file or mapping may have changed while a request was open).
    const previewSeqRef = useRef(0);

    // Mapping / preview.
    const [mode, setMode] = useState<PledgeImportMode>('create_only');
    const [previewing, setPreviewing] = useState(false);
    const [preview, setPreview] = useState<PledgeImportPreviewResponse | null>(null);
    const [previewSignature, setPreviewSignature] = useState<string | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [attentionOnly, setAttentionOnly] = useState(false);

    // Per-row operator decisions (explicit only).
    const [choices, setChoices] = useState<Record<string, RowChoice>>({});
    const [updateIds, setUpdateIds] = useState<Record<string, string>>({});

    // Confirm / results.
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [confirmError, setConfirmError] = useState<string | null>(null);
    const [result, setResult] = useState<PledgeImportConfirmResponse | null>(null);

    // Downloads / messaging.
    const [downloading, setDownloading] = useState<string | null>(null);
    const [errorFormat, setErrorFormat] = useState<'csv' | 'xlsx'>('csv');
    const [notice, setNotice] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const notify = useCallback((message: string) => {
        setNotice(message);
        window.setTimeout(() => setNotice(null), 4500);
    }, []);

    const loadEvent = useCallback(async () => {
        setEventLoading(true);
        setLoadError(null);
        try {
            const { data } = await api.get<EventItem>(`/api/v1/events/${eventId}`);
            setEvent(data);

            if (isCapabilityEnabled(data, 'contributions')) {
                try {
                    const summary = await api.get<EventPledgeSummary>(`/api/v1/events/${eventId}/pledges/summary`);
                    const existing = summary.data.currencies[0]?.currency;
                    if (existing) setDefaultCurrency(existing);
                } catch {
                    // Best effort: the default currency simply stays as USD.
                }
            }
        } catch (err) {
            setLoadError(apiErrorMessage(err));
        } finally {
            setEventLoading(false);
        }
    }, [eventId]);

    useEffect(() => {
        void loadEvent();
    }, [loadEvent]);

    const contributionsEnabled = isCapabilityEnabled(event, 'contributions');

    const normalizedCurrency = defaultCurrency.trim().toUpperCase();
    const currentSignature = signatureOf(mapping, mode, normalizedCurrency);
    const previewFresh = preview !== null && previewSignature === currentSignature;

    const currencyInvalid = normalizedCurrency !== '' && !/^[A-Z]{3}$/.test(normalizedCurrency);

    const resetForNewBatch = useCallback(() => {
        // Invalidate any preview request that is still in flight.
        previewSeqRef.current += 1;
        setPreview(null);
        setPreviewSignature(null);
        setPreviewError(null);
        setChoices({});
        setUpdateIds({});
        setResult(null);
        setConfirmError(null);
        setConfirmOpen(false);
        setAttentionOnly(false);
    }, []);

    const resetAll = () => {
        setFile(null);
        setFileError(null);
        setBatch(null);
        setColumns([]);
        setMapping({});
        setRecognition(null);
        setRowCount(null);
        setSample([]);
        setMode('create_only');
        setError(null);
        setNotice(null);
        resetForNewBatch();
        if (fileInputRef.current) fileInputRef.current.value = '';
    };

    // ---- Template + upload -------------------------------------------------

    const downloadTemplate = async () => {
        setDownloading('template');
        setError(null);
        try {
            const name = await downloadFile(
                `/api/v1/events/${eventId}/pledges/import/template`,
                'pledge-import-template.xlsx',
            );
            notify(`Downloaded ${name}.`);
        } catch (err) {
            setError(await describeDownloadError(err, 'Could not download the template.'));
        } finally {
            setDownloading(null);
        }
    };

    const onFileSelected = (selected: File | undefined) => {
        setFileError(null);
        setNotice(null);
        if (!selected) {
            setFile(null);
            return;
        }
        const ext = selected.name.split('.').pop()?.toLowerCase() ?? '';
        if (!IMPORT_EXTENSIONS.includes(ext)) {
            setFile(null);
            setFileError(`Unsupported file type “.${ext}”. Choose a .csv, .xlsx, or .ods file.`);
            return;
        }
        if (selected.size === 0) {
            setFile(null);
            setFileError('That file is empty.');
            return;
        }
        if (selected.size > IMPORT_MAX_MB * 1024 * 1024) {
            setFile(null);
            setFileError(`That file is too large. The maximum size is ${IMPORT_MAX_MB} MB.`);
            return;
        }
        setFile(selected);
    };

    const uploadFile = async () => {
        if (!file) return;
        setUploading(true);
        setError(null);
        setFileError(null);
        try {
            const form = new FormData();
            form.append('file', file);
            const { data } = await api.post<PledgeImportUploadResponse>(
                `/api/v1/events/${eventId}/pledges/import/upload`,
                form,
                { headers: { 'Content-Type': 'multipart/form-data' } },
            );
            setBatch(data.batch);
            setColumns(data.columns);
            setMapping(data.mapping ?? {});
            setRecognition(data.recognition ?? null);
            setRowCount(typeof data.row_count === 'number' ? data.row_count : null);
            setSample(data.sample ?? []);
            resetForNewBatch();

            const recognized = data.recognition?.recognized.length ?? data.columns.length;
            setNotice(
                `Uploaded “${data.batch.original_filename}”. Recognised ${recognized} of ${data.columns.length} column(s).`,
            );

            // The official template maps automatically, so a fresh preview is
            // prepared immediately — no manual mapping is required.
            void runPreview(data.batch.id, data.mapping ?? {});
        } catch (err) {
            const fieldErrors = validationErrors(err);
            const fileMessage = fieldErrors.file?.[0];
            if (fileMessage) {
                setFileError(fileMessage);
            } else {
                setError(apiErrorMessage(err));
            }
        } finally {
            setUploading(false);
        }
    };

    // ---- Mapping / preview -------------------------------------------------

    const setMappingField = (field: string, column: string) => {
        setMapping((prev) => {
            const next = { ...prev };
            if (column === '') {
                delete next[field];
            } else {
                next[field] = column;
            }
            return next;
        });
    };

    const runPreview = useCallback(
        async (batchId?: number, mappingOverride?: PledgeImportMapping) => {
            const targetId = batchId ?? batch?.id;
            if (targetId === undefined || targetId === null) return;

            const outgoingMapping = mappingOverride ?? mapping;
            const seq = ++previewSeqRef.current;

            setPreviewing(true);
            setPreviewError(null);
            setError(null);

            try {
                const currency = normalizedCurrency === '' ? null : normalizedCurrency;
                const { data } = await api.post<PledgeImportPreviewResponse>(
                    `/api/v1/events/${eventId}/pledges/import/${targetId}/preview`,
                    { mapping: outgoingMapping, mode, default_currency: currency },
                );

                // A newer request has superseded this one: drop the reply so an
                // out-of-order response can never replace fresher results.
                if (seq !== previewSeqRef.current) return;

                setPreview(data);
                setPreviewSignature(signatureOf(outgoingMapping, mode, normalizedCurrency));
                setChoices({});
                setUpdateIds({});
                setResult(null);
                setConfirmError(null);
                setNotice(null);
            } catch (err) {
                if (seq !== previewSeqRef.current) return;

                const fieldErrors = validationErrors(err);
                const fileMessage = fieldErrors.file?.[0];

                // A file-level error (e.g. a missing required column) is not a
                // per-row problem: clear the rows and show the message alone.
                setPreview(null);
                setPreviewSignature(null);
                setPreviewError(fileMessage ?? apiErrorMessage(err));
            } finally {
                if (seq === previewSeqRef.current) {
                    setPreviewing(false);
                }
            }
        },
        [batch, mapping, mode, normalizedCurrency, eventId],
    );

    // ---- Decisions ---------------------------------------------------------

    const setChoice = (rowNumber: number, action: RowChoice) => {
        const key = String(rowNumber);
        setChoices((prev) => {
            const next = { ...prev };
            if (action === '') {
                delete next[key];
            } else {
                next[key] = action;
            }
            return next;
        });
    };

    const setUpdateId = (rowNumber: number, value: string) => {
        const key = String(rowNumber);
        setUpdateIds((prev) => ({ ...prev, [key]: value }));
        // Editing the target pledge id is only possible while "Update" is
        // chosen, so record that choice explicitly so it is sent on confirm.
        setChoices((prev) => ({ ...prev, [key]: 'update' }));
    };

    const skipAllAmbiguous = () => {
        if (!preview) return;
        setChoices((prev) => {
            const next = { ...prev };
            preview.rows
                .filter((row) => row.action === 'ambiguous')
                .forEach((row) => {
                    next[String(row.row)] = 'skip';
                });
            return next;
        });
    };

    const ambiguousRows = preview?.rows.filter((row) => row.action === 'ambiguous') ?? [];

    const unresolvedAmbiguous = ambiguousRows.filter((row) => {
        const key = String(row.row);
        const action = choices[key];
        if (action === 'create' || action === 'skip') return false;
        if (action === 'update') return !/^\d+$/.test((updateIds[key] ?? '').trim());
        return true;
    });

    const buildDecisions = (): Record<string, PledgeImportDecision> => {
        const payload: Record<string, PledgeImportDecision> = {};
        for (const [key, action] of Object.entries(choices)) {
            if (action === 'create' || action === 'skip') {
                payload[key] = { action };
            } else if (action === 'update') {
                const raw = (updateIds[key] ?? '').trim();
                if (/^\d+$/.test(raw)) {
                    payload[key] = { action: 'update', pledge_id: Number(raw) };
                }
            }
        }
        return payload;
    };

    // ---- Confirm -----------------------------------------------------------

    const canConfirm =
        batch !== null &&
        preview !== null &&
        previewFresh &&
        unresolvedAmbiguous.length === 0 &&
        result === null &&
        !confirming;

    const confirmImport = async () => {
        if (!batch || !canConfirm) return;
        setConfirming(true);
        setConfirmError(null);
        try {
            const currency = normalizedCurrency === '' ? null : normalizedCurrency;
            const { data } = await api.post<PledgeImportConfirmResponse>(
                `/api/v1/events/${eventId}/pledges/import/${batch.id}/confirm`,
                { mapping, mode, default_currency: currency, decisions: buildDecisions() },
            );
            setResult(data);
            setConfirmOpen(false);
            notify('Import confirmed.');
        } catch (err) {
            setConfirmOpen(false);
            setConfirmError(apiErrorMessage(err));
        } finally {
            setConfirming(false);
        }
    };

    // ---- Downloads ---------------------------------------------------------

    const downloadErrorReport = async () => {
        if (!batch) return;
        setDownloading('errors');
        setError(null);
        try {
            const name = await downloadFile(
                `/api/v1/events/${eventId}/pledges/import/${batch.id}/errors?format=${errorFormat}`,
                `pledge-import-errors-${batch.id}.${errorFormat}`,
            );
            notify(`Downloaded ${name}.`);
        } catch (err) {
            const message = await describeDownloadError(err, 'Could not download the error report.');
            setError(
                errorFormat === 'xlsx'
                    ? `${message} You can download the report as CSV instead.`
                    : message,
            );
        } finally {
            setDownloading(null);
        }
    };

    const downloadExport = async () => {
        setDownloading('export');
        setError(null);
        try {
            const name = await downloadFile(
                `/api/v1/events/${eventId}/pledges/export`,
                `pledges-${eventId}.xlsx`,
            );
            notify(`Downloaded ${name}.`);
        } catch (err) {
            setError(await describeDownloadError(err, 'Could not export the event pledges.'));
        } finally {
            setDownloading(null);
        }
    };

    // ---- Render ------------------------------------------------------------

    const step = result ? 4 : preview ? 3 : batch ? 2 : 1;
    const unresolvedApplied = result ? result.rows.filter((row) => row.action === 'ambiguous').length : 0;

    return (
        <div className="stack">
            {notice ? <Alert kind="success">{notice}</Alert> : null}
            {error ? <Alert kind="error">{error}</Alert> : null}
            {loadError ? <Alert kind="error">{loadError}</Alert> : null}

            {eventLoading ? (
                <div className="stack">
                    <div>
                        <Link to={`/events/${eventId}/pledges`} className="muted">
                            ← Back to pledges
                        </Link>
                    </div>
                    <LoadingBlock label="Loading event…" />
                </div>
            ) : event && !contributionsEnabled ? (
                <FeatureDisabled eventId={eventId} feature="Contributions (pledges)" />
            ) : (
                <>
                    <div className="row between">
                        <div>
                            <Link to={`/events/${eventId}/pledges`} className="muted">
                                ← Back to pledges
                            </Link>
                            <h1 style={{ marginTop: '0.5rem' }}>Import pledges</h1>
                            <p className="muted" style={{ marginTop: 0, maxWidth: '70ch' }}>
                                {event?.title ?? 'Event'} · upload a spreadsheet of reported pledges. Nothing is written
                                until you review a preview and confirm it explicitly. Invitation Studio does not collect,
                                hold, or verify money.
                            </p>
                        </div>
                        <button
                            type="button"
                            className="btn btn-ghost"
                            onClick={downloadExport}
                            disabled={downloading !== null}
                        >
                            {downloading === 'export' ? 'Exporting…' : 'Export event pledges'}
                        </button>
                    </div>

                    <ol className="import-steps" aria-label="Import progress">
                        {['Upload', 'Review', 'Preview', 'Confirm'].map((label, index) => {
                            const number = index + 1;
                            const state = number < step ? 'done' : number === step ? 'active' : 'todo';
                            return (
                                <li key={label} className={`import-step import-step-${state}`}>
                                    <span className="import-step-index" aria-hidden="true">
                                        {number}
                                    </span>
                                    <span className="import-step-label">{label}</span>
                                    {state === 'active' ? (
                                        <span className="visually-hidden"> (current step)</span>
                                    ) : null}
                                </li>
                            );
                        })}
                    </ol>

                    {/* Step 1 — upload */}
                    <section className="card" aria-labelledby="import-upload-heading">
                        <div className="section-head" style={{ marginBottom: '0.75rem' }}>
                            <div>
                                <h2 id="import-upload-heading">Choose a file</h2>
                                <p className="muted section-sub">
                                    .csv, .xlsx, or .ods, up to {IMPORT_MAX_MB} MB. The first row must be a header row.
                                </p>
                            </div>
                            <button
                                type="button"
                                className="btn btn-ghost"
                                onClick={downloadTemplate}
                                disabled={downloading !== null}
                            >
                                {downloading === 'template' ? 'Preparing…' : 'Download template'}
                            </button>
                        </div>

                        <div className="file-drop">
                            <input
                                ref={fileInputRef}
                                id="pledge-import-file"
                                type="file"
                                accept=".csv,.xlsx,.ods"
                                className="visually-hidden"
                                onChange={(e) => onFileSelected(e.target.files?.[0])}
                            />
                            {file ? (
                                <div className="row between">
                                    <span className="cell-strong">{file.name}</span>
                                    <div className="row">
                                        <button
                                            type="button"
                                            className="btn btn-sm btn-ghost"
                                            onClick={() => fileInputRef.current?.click()}
                                            disabled={uploading}
                                        >
                                            Change file
                                        </button>
                                        <button
                                            type="button"
                                            className="btn btn-sm btn-primary"
                                            onClick={uploadFile}
                                            disabled={uploading}
                                        >
                                            {uploading ? 'Uploading…' : 'Upload and detect columns'}
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <div className="row between">
                                    <span className="muted">No file selected.</span>
                                    <button
                                        type="button"
                                        className="btn btn-ghost"
                                        onClick={() => fileInputRef.current?.click()}
                                        disabled={uploading}
                                    >
                                        Choose file
                                    </button>
                                </div>
                            )}
                        </div>

                        {fileError ? <Alert kind="error">{fileError}</Alert> : null}

                        {batch ? (
                            <p className="muted small" style={{ marginBottom: 0 }}>
                                Batch #{batch.id} · {batch.original_filename} · status {batch.status}. Uploading only
                                stores the file and detects columns — it never writes a pledge.
                            </p>
                        ) : null}
                    </section>

                    {/* Step 2 — review the detected file (mapping is automatic) */}
                    {batch ? (
                        <section className="card" aria-labelledby="import-review-heading">
                            <div className="section-head" style={{ marginBottom: '0.75rem' }}>
                                <div>
                                    <h2 id="import-review-heading">Review the file</h2>
                                    <p className="muted section-sub">
                                        Batch #{batch.id} · {batch.original_filename} ·{' '}
                                        {rowCount === null ? 'row count unavailable' : `${rowCount} data row(s)`} ·{' '}
                                        {columns.length} column(s). Columns are recognised automatically — you do not need
                                        to map them.
                                    </p>
                                </div>
                            </div>

                            {recognition ? (
                                <div className="import-recognition">
                                    <p className="label" style={{ marginBottom: '0.35rem' }}>
                                        Recognised columns
                                    </p>
                                    {recognition.recognized.length === 0 ? (
                                        <p className="muted small" style={{ margin: 0 }}>
                                            No supported columns were recognised in this file.
                                        </p>
                                    ) : (
                                        <ul className="import-col-list">
                                            {recognition.recognized.map((column) => (
                                                <li key={`${column.key}-${column.header}`} className="import-col">
                                                    <span className="cell-strong">{column.header}</span>
                                                    <span className="muted small">{column.label}</span>
                                                    {column.required ? (
                                                        <span className="badge import-create">required</span>
                                                    ) : null}
                                                    {column.legacy ? (
                                                        <span className="badge import-skip">legacy</span>
                                                    ) : null}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            ) : null}

                            {recognition && recognition.missing_required.length > 0 ? (
                                <div style={{ marginTop: '0.5rem' }}>
                                    <Alert kind="error">
                                        This file is missing required column(s):{' '}
                                        {recognition.missing_required
                                            .map((column) => `${column.label} (${column.header})`)
                                            .join(', ')}
                                        . Add the column(s) and upload again, or map them under “Advanced” below.
                                    </Alert>
                                </div>
                            ) : null}

                            {recognition && recognition.notices.length > 0 ? (
                                <div style={{ marginTop: '0.5rem' }}>
                                    <Alert kind="info">
                                        <strong>Not imported:</strong>{' '}
                                        {recognition.notices
                                            .map((notice) => `${notice.header} — ${notice.reason}`)
                                            .join(' ')}
                                    </Alert>
                                </div>
                            ) : null}

                            {sample.length > 0 ? (
                                <div className="import-sample">
                                    <p className="label" style={{ marginBottom: '0.35rem' }}>
                                        Sample of extracted values
                                    </p>
                                    <div className="import-sample-scroll">
                                        <table className="import-sample-table">
                                            <thead>
                                                <tr>
                                                    <th scope="col">Row</th>
                                                    <th scope="col">Pledger</th>
                                                    <th scope="col">Amount</th>
                                                    <th scope="col">Currency</th>
                                                    <th scope="col">Email</th>
                                                    <th scope="col">Phone</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {sample.map((row) => (
                                                    <tr key={row.row}>
                                                        <td>{row.row}</td>
                                                        <td className="cell-strong">
                                                            {asText(row.values.pledger_name)}
                                                        </td>
                                                        <td>{asText(row.values.pledged_amount)}</td>
                                                        <td>{asText(row.values.currency)}</td>
                                                        <td>{asText(row.values.pledger_email)}</td>
                                                        <td>{asText(row.values.pledger_phone)}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    <p className="form-help">
                                        A read-only sample of the first rows. Nothing is stored until you confirm.
                                    </p>
                                </div>
                            ) : null}

                            <div className="grid-2" style={{ marginTop: '0.75rem' }}>
                                <div className="field">
                                    <label className="label" htmlFor="import-mode">
                                        When a row matches an existing pledge
                                    </label>
                                    <select
                                        id="import-mode"
                                        className="select"
                                        value={mode}
                                        onChange={(e) => setMode(e.target.value as PledgeImportMode)}
                                    >
                                        <option value="create_only">Skip the row (create only)</option>
                                        <option value="update_matched">Update the matched pledge</option>
                                    </select>
                                    <p className="form-help">
                                        Re-importing the same file is safe either way: matched rows are skipped or
                                        updated in place and reported contributions are de-duplicated.
                                    </p>
                                </div>
                                <div className="field">
                                    <label className="label" htmlFor="import-currency">
                                        Default currency
                                    </label>
                                    <input
                                        id="import-currency"
                                        className="input"
                                        value={defaultCurrency}
                                        list="import-currency-options"
                                        maxLength={3}
                                        onChange={(e) => setDefaultCurrency(e.target.value.toUpperCase())}
                                        placeholder={FALLBACK_CURRENCY}
                                    />
                                    <datalist id="import-currency-options">
                                        {['USD', 'EUR', 'GBP', 'TZS', 'KES', 'UGX', 'ZAR'].map((code) => (
                                            <option key={code} value={code} />
                                        ))}
                                    </datalist>
                                    <p className="form-help">
                                        Used only for rows without a currency column. Every pledge still stores an
                                        explicit ISO-4217 code.
                                    </p>
                                </div>
                            </div>

                            {currencyInvalid ? (
                                <p className="error-text" role="status">
                                    Enter a three-letter ISO-4217 currency code, or leave it blank to use USD.
                                </p>
                            ) : null}

                            <div className="row" style={{ marginTop: '0.5rem', alignItems: 'center' }}>
                                <button
                                    type="button"
                                    className="btn btn-primary"
                                    onClick={() => void runPreview()}
                                    disabled={previewing || currencyInvalid}
                                >
                                    {previewing ? 'Analysing…' : preview ? 'Preview again' : 'Preview import'}
                                </button>
                                {preview ? (
                                    <span className="muted small">
                                        Preview is {previewFresh ? 'up to date.' : 'out of date — preview again.'}
                                    </span>
                                ) : null}
                            </div>

                            {previewError ? (
                                <div style={{ marginTop: '0.75rem' }}>
                                    <Alert kind="error">{previewError}</Alert>
                                </div>
                            ) : null}

                            <details className="import-advanced">
                                <summary>Advanced: Import a different spreadsheet format</summary>
                                <p className="muted small" style={{ marginTop: '0.5rem' }}>
                                    Only needed if your file does not use Invitation Studio's template columns. Line up
                                    each field with a column from your file; required fields are marked.
                                </p>
                                <div className="import-mapping">
                                    {MAPPING_FIELDS.map((field) => {
                                        const inputId = `map-${field.key}`;
                                        const helpId = `${inputId}-help`;
                                        return (
                                            <div className="import-mapping-row" key={field.key}>
                                                <label className="label" htmlFor={inputId}>
                                                    {field.label}
                                                    {field.required ? (
                                                        <span className="import-required" aria-hidden="true">
                                                            {' '}
                                                            required
                                                        </span>
                                                    ) : null}
                                                </label>
                                                <select
                                                    id={inputId}
                                                    className="select"
                                                    value={mapping[field.key] ?? ''}
                                                    onChange={(e) => setMappingField(field.key, e.target.value)}
                                                    aria-describedby={helpId}
                                                >
                                                    <option value="">Not mapped</option>
                                                    {columns.map((column, index) => (
                                                        <option key={`${column}-${index}`} value={column}>
                                                            {column}
                                                        </option>
                                                    ))}
                                                </select>
                                                <p className="form-help" id={helpId}>
                                                    {field.help}
                                                </p>
                                            </div>
                                        );
                                    })}
                                </div>
                            </details>
                        </section>
                    ) : null}

                    {/* Step 3 — preview */}
                    {preview ? (
                        <section className="card" aria-labelledby="import-preview-heading">
                            <div className="section-head" style={{ marginBottom: '0.75rem' }}>
                                <div>
                                    <h2 id="import-preview-heading">Preview</h2>
                                    <p className="muted section-sub">
                                        Batch #{preview.batch.id} · {preview.batch.original_filename} · This is a dry run.
                                        Nothing is imported until you confirm the next step.
                                    </p>
                                </div>
                                <label className="row" style={{ gap: '0.4rem', alignItems: 'center' }}>
                                    <input
                                        type="checkbox"
                                        checked={attentionOnly}
                                        onChange={(e) => setAttentionOnly(e.target.checked)}
                                    />
                                    <span className="small">Show only rows needing attention</span>
                                </label>
                            </div>

                            <PreviewCounts counts={preview.counts} />

                            {!previewFresh ? (
                                <div style={{ marginTop: '0.75rem' }}>
                                    <Alert kind="error">
                                        The mapping or options changed since this preview. Preview again before
                                        confirming.
                                    </Alert>
                                </div>
                            ) : null}

                            {unresolvedAmbiguous.length > 0 ? (
                                <div style={{ marginTop: '0.75rem' }}>
                                    <Alert kind="error">
                                        {unresolvedAmbiguous.length === 1
                                            ? '1 row needs an explicit decision before you can confirm.'
                                            : `${unresolvedAmbiguous.length} rows need an explicit decision before you can confirm.`}{' '}
                                        <button type="button" className="btn btn-link" onClick={skipAllAmbiguous}>
                                            Skip all of them
                                        </button>
                                    </Alert>
                                </div>
                            ) : null}

                            <div className="import-rows" role="list" aria-label="Import preview rows">
                                {(attentionOnly
                                    ? preview.rows.filter(
                                          (row) => row.action === 'failed' || row.action === 'ambiguous',
                                      )
                                    : preview.rows
                                ).map((row) => (
                                    <ImportRow
                                        key={row.row}
                                        row={row}
                                        currencyFallback={normalizedCurrency || FALLBACK_CURRENCY}
                                        choice={choices[String(row.row)] ?? proposedAction(row)}
                                        updateId={updateIds[String(row.row)] ?? (row.pledge_id ? String(row.pledge_id) : '')}
                                        onChoice={(action) => setChoice(row.row, action)}
                                        onUpdateId={(value) => setUpdateId(row.row, value)}
                                    />
                                ))}
                            </div>
                        </section>
                    ) : null}

                    {/* Step 4 — confirm */}
                    {preview && result === null ? (
                        <section className="card" aria-labelledby="import-confirm-heading">
                            <div className="section-head" style={{ marginBottom: '0.75rem' }}>
                                <div>
                                    <h2 id="import-confirm-heading">Confirm</h2>
                                    <p className="muted section-sub">
                                        {mode === 'update_matched'
                                            ? 'Matched rows will update the existing pledge in place.'
                                            : 'Matched rows will be skipped; only new pledges are created.'}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    className="btn btn-primary"
                                    onClick={() => setConfirmOpen(true)}
                                    disabled={!canConfirm}
                                >
                                    Confirm import
                                </button>
                            </div>

                            <p className="muted" style={{ marginTop: 0, maxWidth: '70ch' }}>
                                Ready to apply: <strong>{preview.counts.created}</strong> new,{' '}
                                <strong>{preview.counts.updated}</strong> updated,{' '}
                                <strong>{preview.counts.skipped}</strong> skipped. Rows still needing a decision:{' '}
                                <strong>{unresolvedAmbiguous.length}</strong>. Invalid rows (never imported):{' '}
                                <strong>{preview.counts.failed}</strong>.
                            </p>

                            {confirmError ? <Alert kind="error">{confirmError}</Alert> : null}

                            <div className="row" style={{ marginTop: '0.5rem', alignItems: 'center' }}>
                                <label className="label" htmlFor="import-error-format" style={{ margin: 0 }}>
                                    Error report format
                                </label>
                                <select
                                    id="import-error-format"
                                    className="select"
                                    style={{ width: 'auto' }}
                                    value={errorFormat}
                                    onChange={(e) => setErrorFormat(e.target.value as 'csv' | 'xlsx')}
                                >
                                    <option value="csv">CSV</option>
                                    <option value="xlsx">Excel (.xlsx)</option>
                                </select>
                                <button
                                    type="button"
                                    className="btn btn-ghost"
                                    onClick={downloadErrorReport}
                                    disabled={downloading !== null}
                                >
                                    {downloading === 'errors' ? 'Preparing…' : 'Download row errors'}
                                </button>
                            </div>
                            <p className="form-help">
                                The report lists invalid and ambiguous rows. CSV always works; Excel needs the PHP zip
                                extension and will tell you if it is unavailable.
                            </p>
                        </section>
                    ) : null}

                    {/* Results */}
                    {result ? (
                        <section className="card" aria-labelledby="import-result-heading">
                            <div className="section-head" style={{ marginBottom: '0.75rem' }}>
                                <div>
                                    <h2 id="import-result-heading">Import complete</h2>
                                    <p className="muted section-sub">
                                        Batch #{result.batch.id} · {result.batch.original_filename}
                                    </p>
                                </div>
                            </div>

                            <PreviewCounts counts={result.counts} applied />

                            <p className="muted small" style={{ marginTop: '0.5rem' }}>
                                “Ambiguous” is the number of rows that needed an explicit decision at analysis time.{' '}
                                {unresolvedApplied > 0
                                    ? `${unresolvedApplied} row(s) were still unresolved and were skipped — import the file again with a decision if they should be applied.`
                                    : 'Every ambiguous row was resolved before confirming.'}
                            </p>

                            <div className="row" style={{ marginTop: '0.85rem', alignItems: 'center' }}>
                                <label className="label" htmlFor="import-error-format-result" style={{ margin: 0 }}>
                                    Error report format
                                </label>
                                <select
                                    id="import-error-format-result"
                                    className="select"
                                    style={{ width: 'auto' }}
                                    value={errorFormat}
                                    onChange={(e) => setErrorFormat(e.target.value as 'csv' | 'xlsx')}
                                >
                                    <option value="csv">CSV</option>
                                    <option value="xlsx">Excel (.xlsx)</option>
                                </select>
                                <button
                                    type="button"
                                    className="btn btn-ghost"
                                    onClick={downloadErrorReport}
                                    disabled={downloading !== null}
                                >
                                    {downloading === 'errors' ? 'Preparing…' : 'Download row errors'}
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-ghost"
                                    onClick={downloadExport}
                                    disabled={downloading !== null}
                                >
                                    {downloading === 'export' ? 'Exporting…' : 'Export event pledges'}
                                </button>
                            </div>

                            {result.counts.failed > 0 ? (
                                <Alert kind="error">
                                    <strong>{result.counts.failed}</strong> row(s) were not imported because they were
                                    invalid. Download the error report, fix them, and import the file again — matched
                                    rows are skipped or updated, so a re-import does not duplicate.
                                </Alert>
                            ) : null}

                            <div className="row" style={{ marginTop: '0.85rem' }}>
                                <Link className="btn btn-primary" to={`/events/${eventId}/pledges`}>
                                    View pledges
                                </Link>
                                <button type="button" className="btn btn-ghost" onClick={resetAll}>
                                    Import another file
                                </button>
                            </div>
                        </section>
                    ) : null}
                </>
            )}

            <ConfirmDialog
                open={confirmOpen}
                title="Confirm this import"
                message={
                    preview ? (
                        <>
                            Apply {preview.counts.created} new pledge(s), update {preview.counts.updated} matched
                            pledge(s), and skip {preview.counts.skipped} row(s). This runs as a single all-or-nothing
                            step and is recorded against batch #{batch?.id}. Reported fulfillments are appended, never
                            overwritten. No money is moved or verified.
                        </>
                    ) : (
                        'Apply this import?'
                    )
                }
                confirmLabel="Apply import"
                busy={confirming}
                onConfirm={confirmImport}
                onCancel={() => setConfirmOpen(false)}
            />
        </div>
    );
}

function PreviewCounts({ counts, applied = false }: { counts: PledgeImportCounts; applied?: boolean }) {
    return (
        <div className="counts" aria-label={applied ? 'Applied import counts' : 'Proposed import counts'}>
            <span className="count">
                <strong>{counts.total_rows}</strong> rows
            </span>
            <span className="count import-count-create">
                <strong>{counts.created}</strong> {applied ? 'created' : 'to create'}
            </span>
            <span className="count import-count-update">
                <strong>{counts.updated}</strong> {applied ? 'updated' : 'to update'}
            </span>
            <span className="count">
                <strong>{counts.skipped}</strong> {applied ? 'skipped' : 'to skip'}
            </span>
            <span className="count import-count-ambiguous">
                <strong>{counts.ambiguous}</strong> ambiguous
            </span>
            <span className="count import-count-failed">
                <strong>{counts.failed}</strong> invalid
            </span>
            <span className="count">
                <strong>{counts.fulfillments_created}</strong> fulfillments{' '}
                {applied ? 'recorded' : 'proposed'}
            </span>
        </div>
    );
}

interface ImportRowProps {
    row: PledgeImportRow;
    currencyFallback: string;
    choice: RowChoice;
    updateId: string;
    onChoice: (action: RowChoice) => void;
    onUpdateId: (value: string) => void;
}

function ImportRow({ row, currencyFallback, choice, updateId, onChoice, onUpdateId }: ImportRowProps) {
    const meta = actionMeta(row.action);
    const currency = row.currency ?? currencyFallback;
    const errors = errorList(row);
    const selectId = `import-row-${row.row}-decision`;
    const pledgeIdInputId = `import-row-${row.row}-pledge-id`;

    return (
        <div className="import-row" role="listitem">
            <div className="import-row-head">
                <span className="import-row-number">Row {row.row}</span>
                <span className={`badge ${meta.className}`}>{meta.label}</span>
                <span className="import-row-name cell-strong">
                    {row.pledger_name ?? 'Unnamed pledger'}
                </span>
                <span className="import-row-amount">
                    {row.pledged_amount_minor === null
                        ? '—'
                        : formatMoney(row.pledged_amount_minor, currency)}
                </span>
            </div>

            <div className="import-row-meta muted small">
                {row.pledger_email ? <span>{row.pledger_email}</span> : null}
                {row.pledger_phone ? <span>{row.pledger_phone}</span> : null}
                {row.guest_id ? <span className="row-tag">guest #{row.guest_id}</span> : null}
                {row.contact_id ? <span className="row-tag">contact #{row.contact_id}</span> : null}
                {row.pledge_id ? <span className="row-tag">existing pledge #{row.pledge_id}</span> : null}
            </div>

            {row.fulfillment && row.fulfillment.amount_minor !== null ? (
                <div className="muted small">
                    Reported fulfillment: {formatMoney(row.fulfillment.amount_minor, currency)}
                    {row.fulfillment.received_on ? ` · ${row.fulfillment.received_on}` : ''}
                    {row.fulfillment.reference ? ` · ref ${row.fulfillment.reference}` : ''}
                </div>
            ) : null}

            {row.reason ? <p className="small import-row-reason">{row.reason}</p> : null}

            {errors.length > 0 ? (
                <ul className="import-row-errors">
                    {errors.map((message) => (
                        <li key={message}>{message}</li>
                    ))}
                </ul>
            ) : null}

            {row.action === 'failed' ? (
                <p className="muted small" style={{ marginBottom: 0 }}>
                    Nothing will be imported for this row. Fix it in the file and import again.
                </p>
            ) : (
                <div className="import-decision">
                    <div className="field" style={{ marginBottom: '0.35rem' }}>
                        <label className="label" htmlFor={selectId}>
                            Decision for row {row.row}
                        </label>
                        <select
                            id={selectId}
                            className="select"
                            value={choice}
                            onChange={(e) => onChoice(e.target.value as RowChoice)}
                        >
                            {choice === '' ? (
                                <option value="" disabled>
                                    Choose an action…
                                </option>
                            ) : null}
                            <option value="create">Create a new pledge</option>
                            <option value="update">Update an existing pledge</option>
                            <option value="skip">Skip this row</option>
                        </select>
                    </div>

                    {choice === 'update' ? (
                        <div className="field" style={{ marginBottom: '0.35rem' }}>
                            <label className="label" htmlFor={pledgeIdInputId}>
                                Existing pledge id (in this event)
                            </label>
                            <input
                                id={pledgeIdInputId}
                                className="input"
                                inputMode="numeric"
                                value={updateId}
                                onChange={(e) => onUpdateId(e.target.value.replace(/[^0-9]/g, ''))}
                                placeholder="e.g. 42"
                            />
                        </div>
                    ) : null}

                    <p className="form-help" style={{ margin: 0 }}>
                        Proposal: {proposalLabel(proposedAction(row))}.
                    </p>
                </div>
            )}
        </div>
    );
}
