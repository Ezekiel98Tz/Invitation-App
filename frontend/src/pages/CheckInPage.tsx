import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, apiErrorMessage, validationErrors } from '../lib/api';
import type {
    Attendance,
    AttendanceList,
    CheckInLookup,
    CheckInState,
    EventItem,
} from '../lib/types';
import { Alert, LoadingBlock, StatusBadge } from '../components/ui';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { EntryQrScanner } from '../components/EntryQrScanner';
import { FeatureDisabled } from '../components/FeatureDisabled';
import { isCapabilityEnabled } from '../lib/capabilities';
import { useRequestGuard } from '../lib/useRequestGuard';

const DEFAULT_PAGE_SIZE = 20;
const PAGE_SIZE_OPTIONS = [20, 50, 100] as const;

interface StateCopy {
    title: string;
    tone: 'valid' | 'info' | 'warn' | 'danger';
    description: string;
}

/**
 * Presentation for each lookup decision. The tone drives the banner colour so
 * operators can read the result at a glance; the copy explains what to do next.
 */
const STATE_COPY: Record<CheckInState, StateCopy> = {
    valid: {
        title: 'Valid credential',
        tone: 'valid',
        description: 'The credential is valid for entry and check-in is open. Confirm below to record the arrival.',
    },
    already_used: {
        title: 'Already checked in',
        tone: 'info',
        description: 'This guest already has an active check-in. Do not check them in again; reverse the existing arrival first if this is a mistake.',
    },
    invalid: {
        title: 'Not recognised',
        tone: 'danger',
        description: 'No matching guest or credential was found for this event. Check the code and try again.',
    },
    revoked: {
        title: 'Credential revoked',
        tone: 'danger',
        description: 'This credential was revoked. Issue a new one from guest management, or use an override if appropriate.',
    },
    expired: {
        title: 'Credential expired',
        tone: 'warn',
        description: 'This credential is outside its valid window. An authorized override may be used if appropriate.',
    },
    not_yet: {
        title: 'Check-in not open yet',
        tone: 'warn',
        description: 'The credential is not valid yet — check-in opens a few hours before the event starts.',
    },
    ineligible: {
        title: 'Not eligible for check-in',
        tone: 'warn',
        description: 'This guest has not accepted their invitation, so a normal check-in is not allowed. An override may be used if appropriate.',
    },
};

function formatWhen(iso: string | null | undefined): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleString();
}

const METHOD_LABELS: Record<string, string> = {
    qr: 'QR scan',
    code: 'Code entry',
    manual: 'Manual',
    override: 'Override',
};

function describeCheckInError(err: unknown, fallback: string): string {
    const status = (err as { response?: { status?: number } })?.response?.status;
    if (status === 401) return 'Your session has expired. Please sign in again.';
    if (status === 403) return 'You do not have permission to check in guests for this event.';
    if (status === 404) return 'That guest could not be found for this event.';
    if (status === 422) {
        const errors = validationErrors(err);
        const first = Object.values(errors)[0];
        if (first && first[0]) return first[0];
        return apiErrorMessage(err) || fallback;
    }
    return apiErrorMessage(err) || fallback;
}

export function CheckInPage() {
    const { id } = useParams<{ id: string }>();
    const eventId = id ?? '';

    const [event, setEvent] = useState<EventItem | null>(null);
    const [eventState, setEventState] = useState<'loading' | 'loaded' | 'failed'>('loading');

    const [lookupMode, setLookupMode] = useState<'manual' | 'qr'>('manual');
    const [code, setCode] = useState('');
    const [qrReference, setQrReference] = useState<string | null>(null);
    const [lookup, setLookup] = useState<CheckInLookup | null>(null);
    const [looking, setLooking] = useState(false);
    const [lookupError, setLookupError] = useState<string | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [checkingIn, setCheckingIn] = useState(false);

    const [overrideOpen, setOverrideOpen] = useState(false);
    const [overrideReason, setOverrideReason] = useState('');
    const [overriding, setOverriding] = useState(false);

    const [attendances, setAttendances] = useState<AttendanceList | null>(null);
    const [loadingArrivals, setLoadingArrivals] = useState(true);
    const [arrivalsError, setArrivalsError] = useState<string | null>(null);
    const [pageSize, setPageSize] = useState<number>(DEFAULT_PAGE_SIZE);

    const [reverseTarget, setReverseTarget] = useState<Attendance | null>(null);
    const [reverseReason, setReverseReason] = useState('');
    const [reversing, setReversing] = useState(false);

    const [notice, setNotice] = useState<string | null>(null);
    const [actionError, setActionError] = useState<string | null>(null);
    const resultRef = useRef<HTMLDivElement>(null);

    const notify = useCallback((message: string) => {
        setNotice(message);
        window.setTimeout(() => setNotice(null), 4000);
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

    const beginRequest = useRequestGuard();

    const loadAttendances = useCallback(
        async (page: number, size: number) => {
            const { signal, isCurrent } = beginRequest();
            setLoadingArrivals(true);
            setArrivalsError(null);
            try {
                const { data } = await api.get<AttendanceList>(`/api/v1/events/${eventId}/attendances`, {
                    params: { page, per_page: size },
                    signal,
                });
                // Ignore a response a newer request has superseded.
                if (!isCurrent()) return;
                setAttendances(data);
            } catch (err) {
                if (signal.aborted || !isCurrent()) return;
                setArrivalsError(describeCheckInError(err, 'Could not load arrivals.'));
            } finally {
                if (isCurrent()) setLoadingArrivals(false);
            }
        },
        [beginRequest, eventId],
    );

    useEffect(() => {
        void loadEvent();
    }, [loadEvent]);

    useEffect(() => {
        if (!lookup) return;
        resultRef.current?.scrollIntoView({ block: 'nearest' });
    }, [lookup]);

    // Wait for the event's capability map before calling the gated attendance
    // endpoint; a disabled feature shows the friendly state instead of a raw
    // 403. If the event could not be read we still try, so a real 403/404 is
    // surfaced rather than hidden.
    useEffect(() => {
        if (eventState === 'loading') return;
        if (eventState === 'loaded' && event && !isCapabilityEnabled(event, 'check_in')) return;
        void loadAttendances(1, DEFAULT_PAGE_SIZE);
    }, [eventState, event, loadAttendances]);

    const changePageSize = (size: number) => {
        setPageSize(size);
        void loadAttendances(1, size);
    };

    const refreshAttendances = () => loadAttendances(attendances?.current_page ?? 1, pageSize);

    const runLookup = async (e: React.FormEvent) => {
        e.preventDefault();
        const trimmed = code.trim();
        if (trimmed === '') return;
        setLooking(true);
        setLookupError(null);
        setActionError(null);
        setLookup(null);
        setQrReference(null);
        try {
            const { data } = await api.get<CheckInLookup>(`/api/v1/events/${eventId}/check-in/lookup`, {
                params: { code: trimmed },
            });
            setLookup(data);
        } catch (err) {
            setLookupError(describeCheckInError(err, 'Lookup failed.'));
        } finally {
            setLooking(false);
        }
    };

    const runQrLookup = async (reference: string) => {
        setLooking(true);
        setLookupError(null);
        setActionError(null);
        setLookup(null);
        setCode('');
        setQrReference(reference);

        try {
            const { data } = await api.get<CheckInLookup>(`/api/v1/events/${eventId}/check-in/lookup`, {
                params: { qr_token: reference },
            });
            setLookup(data);
        } catch (err) {
            setLookupError(describeCheckInError(err, 'QR lookup failed.'));
        } finally {
            setLooking(false);
        }
    };

    const resetLookup = () => {
        setLookup(null);
        setCode('');
        setQrReference(null);
        setLookupError(null);
        setActionError(null);
    };

    const confirmCheckIn = async () => {
        if (!lookup?.guest) return;
        setCheckingIn(true);
        setActionError(null);
        const viaQr = qrReference !== null;
        try {
            await api.post(`/api/v1/events/${eventId}/guests/${lookup.guest.id}/check-in`, {
                ...(viaQr ? { qr_token: qrReference } : { code: code.trim() }),
                method: viaQr ? 'qr' : 'code',
            });
            const name = lookup.guest.name;
            setConfirmOpen(false);
            resetLookup();
            notify(`Checked in ${name}.`);
            await refreshAttendances();
        } catch (err) {
            setConfirmOpen(false);
            setActionError(describeCheckInError(err, 'Check-in failed.'));
        } finally {
            setCheckingIn(false);
        }
    };

    const submitOverride = async () => {
        if (!lookup?.guest || overrideReason.trim() === '') return;
        setOverriding(true);
        setActionError(null);
        try {
            await api.post(`/api/v1/events/${eventId}/guests/${lookup.guest.id}/check-in`, {
                method: 'override',
                note: overrideReason.trim(),
            });
            const name = lookup.guest.name;
            setOverrideOpen(false);
            setOverrideReason('');
            resetLookup();
            notify(`Checked in ${name} with an override.`);
            await refreshAttendances();
        } catch (err) {
            setOverrideOpen(false);
            setActionError(describeCheckInError(err, 'Override check-in failed.'));
        } finally {
            setOverriding(false);
        }
    };

    const confirmReverse = async () => {
        if (!reverseTarget || reverseReason.trim() === '') return;
        setReversing(true);
        setActionError(null);
        try {
            await api.post(
                `/api/v1/events/${eventId}/attendances/${reverseTarget.id}/reverse`,
                { note: reverseReason.trim() },
            );
            setReverseTarget(null);
            setReverseReason('');
            notify('Check-in reversed.');
            await refreshAttendances();
        } catch (err) {
            setReverseTarget(null);
            setActionError(describeCheckInError(err, 'Reversal failed.'));
        } finally {
            setReversing(false);
        }
    };

    if (eventState === 'loading') return <LoadingBlock label="Loading check-in…" />;
    if (eventState === 'loaded' && event && !isCapabilityEnabled(event, 'check_in')) {
        return <FeatureDisabled eventId={eventId} feature="Check-in" />;
    }

    const copy = STATE_COPY[lookup?.state ?? 'invalid'] ?? STATE_COPY.invalid;
    const guest = lookup?.guest ?? null;
    const canCheckIn = lookup?.state === 'valid' && guest !== null;
    const canOverride =
        guest !== null &&
        (lookup?.state === 'ineligible' ||
            lookup?.state === 'not_yet' ||
            lookup?.state === 'expired' ||
            lookup?.state === 'revoked');

    return (
        <div className="stack">
            <div className="row between">
                <div>
                    <Link to={`/events/${eventId}`} className="muted">
                        ← Back to event
                    </Link>
                    <h1 style={{ marginTop: '0.5rem' }}>Check-in</h1>
                    <p className="muted" style={{ marginTop: 0 }}>
                        {event?.title ?? 'Event'} · scan a pass or enter an entry code, review the result, then confirm the arrival.
                    </p>
                </div>
                <button type="button" className="btn btn-ghost" onClick={refreshAttendances} disabled={loadingArrivals}>
                    {loadingArrivals ? 'Refreshing…' : 'Refresh arrivals'}
                </button>
            </div>

            {notice ? <Alert kind="success">{notice}</Alert> : null}
            {actionError ? <Alert kind="error">{actionError}</Alert> : null}

            <div className="grid-2 checkin-grid">
                <section className="card">
                    <h2>Look up a guest</h2>
                    <p className="muted small" style={{ marginTop: 0 }}>
                        Scan a guest pass or enter their short entry code. Validation never records attendance.
                    </p>

                    <div className="checkin-modes" role="group" aria-label="Lookup method">
                        <button
                            type="button"
                            className={`btn btn-sm ${lookupMode === 'manual' ? 'btn-primary' : 'btn-ghost'}`}
                            aria-pressed={lookupMode === 'manual'}
                            onClick={() => setLookupMode('manual')}
                        >
                            Enter code
                        </button>
                        <button
                            type="button"
                            className={`btn btn-sm ${lookupMode === 'qr' ? 'btn-primary' : 'btn-ghost'}`}
                            aria-pressed={lookupMode === 'qr'}
                            onClick={() => setLookupMode('qr')}
                        >
                            Scan QR
                        </button>
                    </div>

                    {lookupMode === 'manual' ? (
                        <form onSubmit={runLookup}>
                            <div className="field">
                                <label className="label" htmlFor="checkin-code">
                                    Entry code
                                </label>
                                <input
                                    id="checkin-code"
                                    className="input"
                                    value={code}
                                    onChange={(e) => setCode(e.target.value)}
                                    autoComplete="off"
                                    autoFocus
                                    maxLength={32}
                                    placeholder="e.g. 7K3-QP9"
                                />
                            </div>
                            <div className="row">
                                <button
                                    type="submit"
                                    className="btn btn-primary"
                                    disabled={looking || code.trim() === ''}
                                >
                                    {looking ? 'Looking up…' : 'Look up'}
                                </button>
                                {lookup || code !== '' ? (
                                    <button type="button" className="btn btn-ghost" onClick={resetLookup}>
                                        Clear
                                    </button>
                                ) : null}
                            </div>
                        </form>
                    ) : lookup || qrReference ? (
                        <div className="qr-lookup-again">
                            {looking ? (
                                <LoadingBlock label="Validating pass…" />
                            ) : (
                                <button type="button" className="btn btn-ghost" onClick={resetLookup}>
                                    Scan another pass
                                </button>
                            )}
                        </div>
                    ) : (
                        <EntryQrScanner disabled={looking} onDecoded={runQrLookup} />
                    )}

                    {lookupError ? (
                        <div style={{ marginTop: '1rem' }}>
                            <Alert kind="error">{lookupError}</Alert>
                        </div>
                    ) : null}

                    {lookup ? (
                        <div
                            ref={resultRef}
                            className={`state-banner state-${copy.tone}`}
                            role="status"
                            aria-live="polite"
                            style={{ marginTop: '1rem' }}
                        >
                            <strong>{copy.title}</strong>
                            <p style={{ margin: '0.25rem 0 0' }}>{copy.description}</p>

                            {guest ? (
                                <dl className="details-list" style={{ marginTop: '0.75rem' }}>
                                    <div>
                                        <dt>Guest</dt>
                                        <dd>{guest.name}</dd>
                                    </div>
                                    <div>
                                        <dt>Event</dt>
                                        <dd>{event?.title ?? 'This event'}</dd>
                                    </div>
                                    <div>
                                        <dt>RSVP</dt>
                                        <dd>
                                            <StatusBadge status={guest.status} />
                                            {typeof lookup.rsvp?.attending_count === 'number'
                                                ? ` · attending ${lookup.rsvp.attending_count}`
                                                : ''}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Credential</dt>
                                        <dd>{lookup.credential ? lookup.credential.reason : 'No credential on file'}</dd>
                                    </div>
                                    {lookup.checked_in ? (
                                        <div>
                                            <dt>Checked in</dt>
                                            <dd>{formatWhen(lookup.attendance?.checked_in_at ?? null)}</dd>
                                        </div>
                                    ) : null}
                                </dl>
                            ) : null}

                            {canCheckIn ? (
                                <button
                                    type="button"
                                    className="btn btn-primary"
                                    style={{ marginTop: '0.75rem' }}
                                    onClick={() => setConfirmOpen(true)}
                                >
                                    Confirm check-in
                                </button>
                            ) : null}

                            {canOverride ? (
                                <button
                                    type="button"
                                    className="btn btn-ghost"
                                    style={{ marginTop: '0.75rem' }}
                                    onClick={() => {
                                        setOverrideReason('');
                                        setOverrideOpen(true);
                                    }}
                                >
                                    Override check-in…
                                </button>
                            ) : null}
                        </div>
                    ) : null}
                </section>

                <section className="card">
                    <div className="row between">
                        <h2 style={{ margin: 0 }}>Arrivals</h2>
                        <span className="count accepted">
                            <strong>{attendances?.checked_in ?? 0}</strong> checked in
                        </span>
                    </div>

                    {loadingArrivals ? (
                        <LoadingBlock label="Loading arrivals…" />
                    ) : arrivalsError ? (
                        <Alert kind="error">{arrivalsError}</Alert>
                    ) : !attendances || attendances.attendances.length === 0 ? (
                        <p className="muted" style={{ marginBottom: 0 }}>
                            No check-ins yet. Look up a guest to record the first arrival.
                        </p>
                    ) : (
                        <ul className="arrivals-list">
                            {attendances.attendances.map((a) => (
                                <li key={a.id} className="arrival">
                                    <div className="arrival-main">
                                        <span className="cell-strong">{a.guest?.name ?? `Guest #${a.guest_id}`}</span>
                                        <span className="muted small">{formatWhen(a.checked_in_at)}</span>
                                    </div>
                                    <div className="row" style={{ gap: '0.4rem', alignItems: 'center' }}>
                                        <span className="row-tag">{METHOD_LABELS[a.method] ?? a.method}</span>
                                        <span className="row-tag">party {a.party_size}</span>
                                        {a.status === 'valid' ? (
                                            <StatusBadge status="checked-in" />
                                        ) : (
                                            <StatusBadge status="reversed" />
                                        )}
                                        {a.status === 'valid' ? (
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-ghost"
                                                onClick={() => {
                                                    setReverseReason('');
                                                    setReverseTarget(a);
                                                }}
                                            >
                                                Reverse
                                            </button>
                                        ) : null}
                                    </div>
                                    {a.status !== 'valid' && a.reversal_reason ? (
                                        <p className="muted small" style={{ margin: 0 }}>
                                            Reversed: {a.reversal_reason}
                                        </p>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}

                    {attendances && attendances.total > 0 ? (
                        <div
                            className="row between pagination"
                            style={{ marginTop: '0.75rem' }}
                            aria-label="Arrivals pagination"
                        >
                            <div className="row" style={{ gap: '0.75rem', alignItems: 'center' }}>
                                <span className="muted small">
                                    Page {attendances.current_page} of {attendances.last_page} · {attendances.total} record
                                    {attendances.total === 1 ? '' : 's'}
                                </span>
                                <label
                                    className="muted small"
                                    htmlFor="arrivals-page-size"
                                    style={{ display: 'inline-flex', gap: '0.35rem', alignItems: 'center' }}
                                >
                                    Rows
                                    <select
                                        id="arrivals-page-size"
                                        className="select"
                                        style={{ width: 'auto' }}
                                        value={pageSize}
                                        onChange={(e) => changePageSize(Number(e.target.value))}
                                    >
                                        {PAGE_SIZE_OPTIONS.map((size) => (
                                            <option key={size} value={size}>
                                                {size}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                            <div className="row">
                                <button
                                    type="button"
                                    className="btn btn-sm btn-ghost"
                                    disabled={!attendances.prev_page_url}
                                    onClick={() => void loadAttendances(attendances.current_page - 1, pageSize)}
                                >
                                    Previous
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-ghost"
                                    disabled={!attendances.next_page_url}
                                    onClick={() => void loadAttendances(attendances.current_page + 1, pageSize)}
                                >
                                    Next
                                </button>
                            </div>
                        </div>
                    ) : null}
                </section>
            </div>

            <ConfirmDialog
                open={confirmOpen}
                title="Confirm check-in"
                message={
                    <>
                        Record attendance for <strong>{guest?.name}</strong>? This adds the guest to today&apos;s
                        arrivals.
                    </>
                }
                confirmLabel="Check in"
                busy={checkingIn}
                onConfirm={confirmCheckIn}
                onCancel={() => setConfirmOpen(false)}
            />

            <ConfirmDialog
                open={overrideOpen}
                title="Override check-in"
                message={
                    <>
                        Check in <strong>{guest?.name}</strong> even though the credential is not valid. This is recorded
                        and audited, and requires a reason.
                    </>
                }
                confirmLabel="Override and check in"
                danger
                busy={overriding}
                confirmDisabled={overrideReason.trim() === ''}
                onConfirm={submitOverride}
                onCancel={() => setOverrideOpen(false)}
            >
                <div className="field" style={{ marginTop: '0.75rem' }}>
                    <label className="label" htmlFor="override-reason">
                        Reason (required)
                    </label>
                    <textarea
                        id="override-reason"
                        className="textarea"
                        rows={3}
                        maxLength={500}
                        value={overrideReason}
                        onChange={(e) => setOverrideReason(e.target.value)}
                    />
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={reverseTarget !== null}
                title="Reverse check-in"
                message={
                    <>
                        Reverse the arrival for <strong>{reverseTarget?.guest?.name ?? 'this guest'}</strong>? The
                        original record is kept for audit and requires a reason.
                    </>
                }
                confirmLabel="Reverse check-in"
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
                    <label className="label" htmlFor="reverse-reason">
                        Reason (required)
                    </label>
                    <textarea
                        id="reverse-reason"
                        className="textarea"
                        rows={3}
                        maxLength={500}
                        value={reverseReason}
                        onChange={(e) => setReverseReason(e.target.value)}
                    />
                </div>
            </ConfirmDialog>
        </div>
    );
}
