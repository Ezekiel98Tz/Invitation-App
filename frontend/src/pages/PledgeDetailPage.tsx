import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, apiErrorMessage, validationErrors } from '../lib/api';
import type { EventItem, Pledge, PledgeFulfillment, PledgeMutationResult } from '../lib/types';
import { majorToMinor, formatMoney } from '../lib/money';
import { isCapabilityEnabled } from '../lib/capabilities';
import { Alert, Field, LoadingBlock } from '../components/ui';
import { Modal } from '../components/Modal';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { FeatureDisabled } from '../components/FeatureDisabled';
import { PledgeFormModal } from '../components/PledgeFormModal';
import { FulfillmentStatusBadge, methodLabel, PledgeStatusBadge, PledgeStoredStatusBadge } from '../components/PledgeBadges';

const METHOD_OPTIONS: { value: string; label: string }[] = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'mobile_money', label: 'Mobile money' },
    { value: 'cheque', label: 'Cheque' },
    { value: 'other', label: 'Other' },
];

function formatWhen(iso: string | null | undefined): string {
    if (!iso) return '—';
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? iso : date.toLocaleString();
}

function todayInput(): string {
    const now = new Date();
    const pad = (value: number) => String(value).padStart(2, '0');
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

interface RecordForm {
    amount: string;
    received_on: string;
    method: string;
    method_detail: string;
    reference: string;
    note: string;
}

function emptyRecordForm(): RecordForm {
    return { amount: '', received_on: todayInput(), method: '', method_detail: '', reference: '', note: '' };
}

export function PledgeDetailPage() {
    const { id, pledgeId } = useParams<{ id: string; pledgeId: string }>();
    const eventId = id ?? '';
    const currentPledgeId = pledgeId ?? '';

    const [event, setEvent] = useState<EventItem | null>(null);
    const [eventState, setEventState] = useState<'loading' | 'loaded' | 'failed'>('loading');
    const [pledge, setPledge] = useState<Pledge | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [toast, setToast] = useState<string | null>(null);

    const [editOpen, setEditOpen] = useState(false);

    const [recordOpen, setRecordOpen] = useState(false);
    const [recordForm, setRecordForm] = useState<RecordForm>(emptyRecordForm);
    const [recordErrors, setRecordErrors] = useState<Record<string, string[]>>({});
    const [recording, setRecording] = useState(false);

    const [reverseTarget, setReverseTarget] = useState<PledgeFulfillment | null>(null);
    const [reverseReason, setReverseReason] = useState('');
    const [reversing, setReversing] = useState(false);

    const [cancelOpen, setCancelOpen] = useState(false);
    const [cancelReason, setCancelReason] = useState('');
    const [cancelling, setCancelling] = useState(false);

    const notify = useCallback((message: string) => {
        setToast(message);
        window.setTimeout(() => setToast(null), 3500);
    }, []);

    const loadEvent = useCallback(async () => {
        try {
            const { data } = await api.get<EventItem>(`/api/v1/events/${eventId}`);
            setEvent(data);
            setEventState('loaded');
        } catch {
            // Best-effort heading and capability source only.
            setEventState('failed');
        }
    }, [eventId]);

    const loadPledge = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const { data } = await api.get<Pledge>(`/api/v1/events/${eventId}/pledges/${currentPledgeId}`);
            setPledge(data);
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setLoading(false);
        }
    }, [eventId, currentPledgeId]);

    useEffect(() => {
        void loadEvent();
    }, [loadEvent]);

    // Wait for the event's capability map before calling the gated pledge
    // endpoint; a disabled feature shows the friendly state instead of a raw
    // 403. If the event could not be read we still try, so a real 403/404 is
    // surfaced rather than hidden.
    useEffect(() => {
        if (eventState === 'loading') return;
        if (eventState === 'loaded' && event && !isCapabilityEnabled(event, 'contributions')) return;
        void loadPledge();
    }, [eventState, event, loadPledge]);

    const openRecord = () => {
        setRecordForm(emptyRecordForm());
        setRecordErrors({});
        setRecordOpen(true);
    };

    const submitRecord = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!pledge) return;
        setRecordErrors({});

        const currency = pledge.currency;
        const amountMinor = majorToMinor(Number(recordForm.amount), currency);
        if (amountMinor === null || amountMinor < 1) {
            setRecordErrors({ amount_minor: ['Enter an amount greater than zero.'] });
            return;
        }

        setRecording(true);
        try {
            const { data } = await api.post<PledgeMutationResult>(
                `/api/v1/events/${eventId}/pledges/${pledge.id}/fulfillments`,
                {
                    amount_minor: amountMinor,
                    method: recordForm.method === '' ? null : recordForm.method,
                    method_detail:
                        recordForm.method === 'other' && recordForm.method_detail.trim() !== ''
                            ? recordForm.method_detail.trim()
                            : null,
                    received_on: recordForm.received_on === '' ? null : recordForm.received_on,
                    reference: recordForm.reference.trim() === '' ? null : recordForm.reference.trim(),
                    note: recordForm.note.trim() === '' ? null : recordForm.note.trim(),
                },
            );
            setPledge(data.pledge);
            setRecordOpen(false);
            notify('Fulfillment reported.');
        } catch (err) {
            const fieldErrors = validationErrors(err);
            setRecordErrors(fieldErrors);
            if (Object.keys(fieldErrors).length === 0) {
                setError(apiErrorMessage(err));
            }
        } finally {
            setRecording(false);
        }
    };

    const confirmReverse = async () => {
        if (!pledge || !reverseTarget || reverseReason.trim() === '') return;
        setReversing(true);
        try {
            const { data } = await api.post<PledgeMutationResult>(
                `/api/v1/events/${eventId}/pledges/${pledge.id}/fulfillments/${reverseTarget.id}/reverse`,
                { note: reverseReason.trim() },
            );
            setPledge(data.pledge);
            setReverseTarget(null);
            setReverseReason('');
            notify('Fulfillment reversed. The original entry is kept for audit.');
        } catch (err) {
            setReverseTarget(null);
            setError(apiErrorMessage(err));
        } finally {
            setReversing(false);
        }
    };

    const confirmCancel = async () => {
        if (!pledge) return;
        setCancelling(true);
        try {
            const { data } = await api.post<Pledge>(
                `/api/v1/events/${eventId}/pledges/${pledge.id}/cancel`,
                { reason: cancelReason.trim() === '' ? null : cancelReason.trim() },
            );
            setPledge(data);
            setCancelOpen(false);
            setCancelReason('');
            notify('Pledge cancelled. Its history is kept.');
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setCancelling(false);
        }
    };

    if (eventState === 'loading') return <LoadingBlock label="Loading pledge…" />;
    if (eventState === 'loaded' && event && !isCapabilityEnabled(event, 'contributions')) {
        return <FeatureDisabled eventId={eventId} feature="Contributions (pledges)" />;
    }
    if (loading) return <LoadingBlock label="Loading pledge…" />;
    if (!pledge) return <Alert kind="error">{error ?? 'Unable to load this pledge.'}</Alert>;

    const fulfillments = [...(pledge.fulfillments ?? [])].sort((a, b) => a.id - b.id);
    const reversedIds = new Set(
        fulfillments
            .filter((entry) => entry.reverses_fulfillment_id != null)
            .map((entry) => entry.reverses_fulfillment_id as number),
    );

    const isCancelled = pledge.status === 'cancelled' || pledge.derived_status === 'cancelled';
    const pledged = pledge.pledged_amount_minor;
    const fulfilled = pledge.fulfilled_minor;
    const overFulfilled = fulfilled > pledged;
    const progressPct = pledged <= 0 ? (fulfilled > 0 ? 100 : 0) : Math.min(100, Math.round((fulfilled / pledged) * 100));

    return (
        <div className="stack">
            <div className="row between">
                <div>
                    <Link to={`/events/${eventId}/pledges`} className="muted">
                        ← Back to pledges
                    </Link>
                    <h1 style={{ marginTop: '0.5rem' }}>{pledge.pledger_name ?? 'Unnamed pledge'}</h1>
                    <p className="muted" style={{ marginTop: 0 }}>
                        {event?.title ?? 'Event'} · pledge #{pledge.id}
                    </p>
                    <div className="row" style={{ marginTop: '0.4rem' }}>
                        <PledgeStatusBadge status={pledge.derived_status} />
                        <PledgeStoredStatusBadge status={pledge.status} />
                        <span className="row-tag">{pledge.source}</span>
                    </div>
                </div>
                <div className="row">
                    <button type="button" className="btn btn-ghost" onClick={() => setEditOpen(true)}>
                        Edit pledge
                    </button>
                    {!isCancelled ? (
                        <button type="button" className="btn btn-primary" onClick={openRecord}>
                            Record fulfillment
                        </button>
                    ) : null}
                    {!isCancelled ? (
                        <button
                            type="button"
                            className="btn btn-danger-solid"
                            onClick={() => {
                                setCancelReason('');
                                setCancelOpen(true);
                            }}
                        >
                            Cancel pledge
                        </button>
                    ) : null}
                </div>
            </div>

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error ? <Alert kind="error">{error}</Alert> : null}

            {isCancelled ? (
                <Alert kind="error">
                    This pledge is cancelled. Cancelled pledges are excluded from event money totals, but their
                    fulfillment history is kept for audit.
                </Alert>
            ) : null}

            <div className="grid-2 pledge-detail-grid">
                <section className="card">
                    <h3 style={{ marginTop: 0 }}>Balances</h3>
                    <dl className="details-list">
                        <div>
                            <dt>Pledged</dt>
                            <dd className="cell-strong">{formatMoney(pledged, pledge.currency)}</dd>
                        </div>
                        <div>
                            <dt>Reported fulfilled</dt>
                            <dd>{formatMoney(fulfilled, pledge.currency)}</dd>
                        </div>
                        <div>
                            <dt>Outstanding</dt>
                            <dd className="cell-strong">{formatMoney(pledge.outstanding_minor, pledge.currency)}</dd>
                        </div>
                    </dl>
                    <div
                        className="progress"
                        role="progressbar"
                        aria-valuenow={progressPct}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-label="Reported fulfillment as a share of the pledged amount"
                    >
                        <div className="progress-bar" style={{ width: `${progressPct}%` }} />
                    </div>
                    {overFulfilled ? (
                        <p className="muted small" style={{ marginBottom: 0 }}>
                            Reported fulfillment exceeds the pledged amount. Over-fulfillment is recorded as reported
                            and is never clamped.
                        </p>
                    ) : (
                        <p className="muted small" style={{ marginBottom: 0 }}>
                            Amounts are what the client reported. Invitation Studio does not collect or verify money.
                        </p>
                    )}
                </section>

                <section className="card">
                    <h3 style={{ marginTop: 0 }}>Pledge details</h3>
                    <dl className="details-list">
                        <div>
                            <dt>Name</dt>
                            <dd>{pledge.pledger_name ?? '—'}</dd>
                        </div>
                        <div>
                            <dt>Email</dt>
                            <dd>{pledge.pledger_email ?? '—'}</dd>
                        </div>
                        <div>
                            <dt>Phone</dt>
                            <dd>{pledge.pledger_phone ?? '—'}</dd>
                        </div>
                        <div>
                            <dt>Currency</dt>
                            <dd>{pledge.currency}</dd>
                        </div>
                        <div>
                            <dt>Linked guest</dt>
                            <dd>{pledge.guest_id ? `Guest #${pledge.guest_id}` : '—'}</dd>
                        </div>
                        <div>
                            <dt>Linked contact</dt>
                            <dd>
                                {pledge.contact_id ? (
                                    <Link to={`/contacts/${pledge.contact_id}`}>Contact #{pledge.contact_id}</Link>
                                ) : (
                                    '—'
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt>Note</dt>
                            <dd>{pledge.note ?? '—'}</dd>
                        </div>
                        <div>
                            <dt>Created</dt>
                            <dd>{formatWhen(pledge.created_at)}</dd>
                        </div>
                        <div>
                            <dt>Last change</dt>
                            <dd>{formatWhen(pledge.updated_at)}</dd>
                        </div>
                    </dl>
                </section>
            </div>

            <section className="card">
                <div className="row between">
                    <div>
                        <h3 style={{ margin: 0 }}>Fulfillment history</h3>
                        <p className="muted small" style={{ margin: '0.15rem 0 0' }}>
                            Append-only. Corrections are recorded as reversals; originals are never edited or deleted.
                        </p>
                    </div>
                    {!isCancelled ? (
                        <button type="button" className="btn btn-ghost btn-sm" onClick={openRecord}>
                            Record fulfillment
                        </button>
                    ) : null}
                </div>

                {fulfillments.length === 0 ? (
                    <p className="muted" style={{ marginBottom: 0 }}>
                        No fulfillments reported yet.
                    </p>
                ) : (
                    <ul className="fulfillment-list">
                        {fulfillments.map((entry) => {
                            const reversalPossible =
                                !isCancelled &&
                                !entry.is_reversal &&
                                entry.status === 'recorded' &&
                                !reversedIds.has(entry.id);
                            return (
                                <li key={entry.id} className="fulfillment">
                                    <div className="fulfillment-main">
                                        <span className="cell-strong">
                                            {formatMoney(entry.amount_minor, pledge.currency)}
                                        </span>
                                        <span className="muted small">
                                            {entry.received_on ? `Received ${entry.received_on}` : 'No date reported'}
                                        </span>
                                    </div>
                                    <div className="row" style={{ gap: '0.4rem', alignItems: 'center' }}>
                                        <span className="row-tag">{methodLabel(entry.method)}</span>
                                        {entry.method_detail ? (
                                            <span className="muted small">{entry.method_detail}</span>
                                        ) : null}
                                        <FulfillmentStatusBadge isReversal={entry.is_reversal} status={entry.status} />
                                        {reversalPossible ? (
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-ghost"
                                                onClick={() => {
                                                    setReverseReason('');
                                                    setReverseTarget(entry);
                                                }}
                                            >
                                                Reverse
                                            </button>
                                        ) : null}
                                    </div>
                                    {entry.is_reversal ? (
                                        <p className="muted small" style={{ margin: 0 }}>
                                            Reverses fulfillment #{entry.reverses_fulfillment_id}
                                        </p>
                                    ) : null}
                                    {entry.reference ? (
                                        <p className="muted small" style={{ margin: 0 }}>
                                            Reference: {entry.reference}
                                        </p>
                                    ) : null}
                                    {entry.note ? (
                                        <p className="muted small" style={{ margin: 0 }}>
                                            {entry.note}
                                        </p>
                                    ) : null}
                                    <p className="muted small" style={{ margin: 0 }}>
                                        Entry #{entry.id}
                                        {entry.recorded_by ? ` · recorded by user #${entry.recorded_by}` : ''}
                                        {entry.created_at ? ` · ${formatWhen(entry.created_at)}` : ''}
                                    </p>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>

            <PledgeFormModal
                open={editOpen}
                eventId={eventId}
                pledge={pledge}
                onClose={() => setEditOpen(false)}
                onSaved={(updated) => {
                    setPledge(updated);
                    setEditOpen(false);
                    notify('Pledge updated.');
                }}
                onError={setError}
            />

            <Modal
                open={recordOpen}
                title="Record fulfillment"
                onClose={() => setRecordOpen(false)}
                busy={recording}
                maxWidth={520}
            >
                <form onSubmit={submitRecord}>
                    <p className="muted small" style={{ marginTop: 0 }}>
                        Record an amount the client reports they have given. This is not a payment — no money is moved
                        or verified. Reported amounts are never clamped, even over the pledged total.
                    </p>
                    <div className="grid-2">
                        <Field
                            label={`Amount (${pledge.currency})`}
                            error={recordErrors.amount_minor?.[0]}
                            htmlFor="fulfillment-amount"
                        >
                            <input
                                id="fulfillment-amount"
                                className="input"
                                inputMode="decimal"
                                value={recordForm.amount}
                                onChange={(e) => setRecordForm({ ...recordForm, amount: e.target.value })}
                                placeholder="0.00"
                            />
                        </Field>
                        <Field label="Received on (optional)" error={recordErrors.received_on?.[0]} htmlFor="fulfillment-date">
                            <input
                                id="fulfillment-date"
                                className="input"
                                type="date"
                                value={recordForm.received_on}
                                onChange={(e) => setRecordForm({ ...recordForm, received_on: e.target.value })}
                            />
                        </Field>
                    </div>
                    <Field label="Method (optional)" error={recordErrors.method?.[0]} htmlFor="fulfillment-method">
                        <select
                            id="fulfillment-method"
                            className="select"
                            value={recordForm.method}
                            onChange={(e) => setRecordForm({ ...recordForm, method: e.target.value })}
                        >
                            <option value="">Unspecified</option>
                            {METHOD_OPTIONS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    {recordForm.method === 'other' ? (
                        <Field
                            label="Method detail (optional)"
                            error={recordErrors.method_detail?.[0]}
                            htmlFor="fulfillment-method-detail"
                        >
                            <input
                                id="fulfillment-method-detail"
                                className="input"
                                value={recordForm.method_detail}
                                onChange={(e) => setRecordForm({ ...recordForm, method_detail: e.target.value })}
                                maxLength={255}
                            />
                        </Field>
                    ) : null}
                    <Field label="Reference (optional)" error={recordErrors.reference?.[0]} htmlFor="fulfillment-reference">
                        <input
                            id="fulfillment-reference"
                            className="input"
                            value={recordForm.reference}
                            onChange={(e) => setRecordForm({ ...recordForm, reference: e.target.value })}
                            maxLength={255}
                        />
                    </Field>
                    <Field label="Note (optional)" error={recordErrors.note?.[0]} htmlFor="fulfillment-note">
                        <textarea
                            id="fulfillment-note"
                            className="textarea"
                            rows={3}
                            value={recordForm.note}
                            onChange={(e) => setRecordForm({ ...recordForm, note: e.target.value })}
                            maxLength={2000}
                        />
                    </Field>
                    <div className="modal-footer">
                        <button type="button" className="btn btn-ghost" onClick={() => setRecordOpen(false)} disabled={recording}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={recording || recordForm.amount.trim() === ''}>
                            {recording ? 'Recording…' : 'Record fulfillment'}
                        </button>
                    </div>
                </form>
            </Modal>

            <ConfirmDialog
                open={reverseTarget !== null}
                title="Reverse fulfillment"
                message={
                    <>
                        Reverse the reported fulfillment of{' '}
                        <strong>
                            {reverseTarget ? formatMoney(reverseTarget.amount_minor, pledge.currency) : ''}
                        </strong>
                        ? A compensating reversal entry is added and the original is kept for audit. This cannot be
                        undone.
                    </>
                }
                confirmLabel="Reverse fulfillment"
                danger
                busy={reversing}
                confirmDisabled={reverseReason.trim() === ''}
                onConfirm={confirmReverse}
                onCancel={() => {
                    setReverseTarget(null);
                    setReverseReason('');
                }}
            >
                <div className="field" style={{ marginTop: '0.75rem' }}>
                    <label className="label" htmlFor="reversal-reason">
                        Reason (recorded as the reversal note)
                    </label>
                    <textarea
                        id="reversal-reason"
                        className="textarea"
                        rows={3}
                        maxLength={2000}
                        value={reverseReason}
                        onChange={(e) => setReverseReason(e.target.value)}
                    />
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={cancelOpen}
                title="Cancel pledge"
                message={
                    <>
                        Cancel this pledge? It is kept with its full history but excluded from event money totals. Its
                        reported fulfillments are not deleted. Invitation and check-in are never affected.
                    </>
                }
                confirmLabel="Cancel pledge"
                danger
                busy={cancelling}
                onConfirm={confirmCancel}
                onCancel={() => {
                    setCancelOpen(false);
                    setCancelReason('');
                }}
            >
                <div className="field" style={{ marginTop: '0.75rem' }}>
                    <label className="label" htmlFor="cancel-reason">
                        Reason (optional)
                    </label>
                    <textarea
                        id="cancel-reason"
                        className="textarea"
                        rows={3}
                        maxLength={500}
                        value={cancelReason}
                        onChange={(e) => setCancelReason(e.target.value)}
                    />
                </div>
            </ConfirmDialog>
        </div>
    );
}
