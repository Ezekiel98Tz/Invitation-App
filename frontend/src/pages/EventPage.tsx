import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, apiErrorMessage } from '../lib/api';
import type { Delivery, EventItem, Paginated } from '../lib/types';
import { isCapabilityEnabled } from '../lib/capabilities';
import { Alert, LoadingBlock } from '../components/ui';
import { GuestsPanel } from '../components/GuestsPanel';
import { EventFormModal } from '../components/EventFormModal';
import { useRequestGuard } from '../lib/useRequestGuard';

type Tab = 'guests' | 'invitations' | 'deliveries';

const PAGE_SIZE_OPTIONS = [20, 50, 100] as const;

interface Preview {
    mail?: { subject: string; html?: string; text?: string };
    sms?: { message: string };
    whatsapp?: { message: string };
}

export function EventPage() {
    const { id } = useParams<{ id: string }>();
    const eventId = id ?? '';
    const [event, setEvent] = useState<EventItem | null>(null);
    const [tab, setTab] = useState<Tab>('guests');
    const [error, setError] = useState<string | null>(null);
    const [toast, setToast] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const [preview, setPreview] = useState<Preview | null>(null);
    const [failed, setFailed] = useState<Delivery[]>([]);
    const [failedPage, setFailedPage] = useState<Paginated<Delivery> | null>(null);
    const [failedPageSize, setFailedPageSize] = useState<number>(20);
    const [editOpen, setEditOpen] = useState(false);

    const eventGuard = useRequestGuard();
    const previewGuard = useRequestGuard();
    const failedGuard = useRequestGuard();

    const notify = (message: string) => {
        setToast(message);
        window.setTimeout(() => setToast(null), 3500);
    };

    const loadEvent = useCallback(async () => {
        const { signal, isCurrent } = eventGuard();
        try {
            const { data } = await api.get<EventItem>(`/api/v1/events/${eventId}`, { signal });
            if (!isCurrent()) return;
            setEvent(data);
        } catch (err) {
            if (signal.aborted || !isCurrent()) return;
            setError(apiErrorMessage(err));
        }
    }, [eventGuard, eventId]);

    const loadPreview = useCallback(async () => {
        const { signal, isCurrent } = previewGuard();
        try {
            const { data } = await api.get<{ channels: Preview }>(`/api/v1/events/${eventId}/invitations/preview`, {
                signal,
            });
            if (!isCurrent()) return;
            setPreview(data.channels);
        } catch {
            // preview is best-effort
        }
    }, [previewGuard, eventId]);

    const loadFailed = useCallback(
        async (page: number, size: number) => {
            const { signal, isCurrent } = failedGuard();
            try {
                const { data } = await api.get<Paginated<Delivery>>(`/api/v1/events/${eventId}/deliveries/failed`, {
                    params: { page, per_page: size },
                    signal,
                });
                if (!isCurrent()) return;
                setFailed(data.data);
                setFailedPage(data);
            } catch (err) {
                if (signal.aborted || !isCurrent()) return;
                setError(apiErrorMessage(err));
            }
        },
        [failedGuard, eventId],
    );

    const reloadFailed = () => loadFailed(failedPage?.current_page ?? 1, failedPageSize);

    const changeFailedPageSize = (size: number) => {
        setFailedPageSize(size);
        void loadFailed(1, size);
    };

    useEffect(() => {
        void loadEvent();
        void loadPreview();
        void loadFailed(1, 20);
    }, [loadEvent, loadPreview, loadFailed]);

    const sendInvitations = async () => {
        if (!window.confirm('Send invitations to all unsent guests?')) return;
        setBusy(true);
        try {
            const { data } = await api.post<{ queued: number }>(`/api/v1/events/${eventId}/invitations/send`);
            notify(`Invitations queued: ${data.queued}`);
            await reloadFailed();
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    const retry = async (delivery: Delivery) => {
        try {
            await api.post(`/api/v1/events/${eventId}/deliveries/${delivery.id}/retry`);
            notify('Retry queued.');
            await reloadFailed();
        } catch (err) {
            setError(apiErrorMessage(err));
        }
    };

    const retryAll = async () => {
        setBusy(true);
        try {
            const { data } = await api.post<{ queued: number }>(`/api/v1/events/${eventId}/deliveries/retry-failed`);
            notify(`Queued: ${data.queued}`);
            await reloadFailed();
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    const contributionsEnabled = isCapabilityEnabled(event, 'contributions');
    const checkInEnabled = isCapabilityEnabled(event, 'check_in');

    return (
        <div className="stack">
            <div className="row between">
                <div>
                    <Link to="/dashboard" className="muted">
                        ← Back
                    </Link>
                    <h1 style={{ marginTop: '0.5rem' }}>{event?.title ?? 'Event'}</h1>
                    {event ? (
                        <p className="muted" style={{ marginTop: 0 }}>
                            {event.venue} · {event.event_start ? new Date(event.event_start).toLocaleString() : ''}
                        </p>
                    ) : null}
                </div>
                <div className="row">
                    <Link
                        className="btn btn-ghost"
                        style={contributionsEnabled ? undefined : { opacity: 0.55 }}
                        to={`/events/${eventId}/pledges`}
                        title={
                            contributionsEnabled
                                ? 'Pledges'
                                : 'Contributions is disabled for this event — open to learn how to enable it'
                        }
                    >
                        Pledges{contributionsEnabled ? '' : ' · off'}
                    </Link>
                    <Link
                        className="btn btn-ghost"
                        style={checkInEnabled ? undefined : { opacity: 0.55 }}
                        to={`/events/${eventId}/check-in`}
                        title={
                            checkInEnabled
                                ? 'Check-in'
                                : 'Check-in is disabled for this event — open to learn how to enable it'
                        }
                    >
                        Check-in{checkInEnabled ? '' : ' · off'}
                    </Link>
                    <button type="button" className="btn btn-ghost" onClick={() => setEditOpen(true)}>
                        Edit event
                    </button>
                    <Link className="btn btn-primary" to={`/events/${eventId}/invitation`}>
                        Invitation design
                    </Link>
                </div>
            </div>

            <EventFormModal
                open={editOpen}
                event={event}
                onClose={() => setEditOpen(false)}
                onSaved={() => {
                    setEditOpen(false);
                    void loadEvent();
                    notify('Event updated.');
                }}
                onError={setError}
            />

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error ? <Alert kind="error">{error}</Alert> : null}

            <div className="card">
                <div className="tabs">
                    <button className={`tab${tab === 'guests' ? ' active' : ''}`} onClick={() => setTab('guests')}>
                        Guests
                    </button>
                    <button
                        className={`tab${tab === 'invitations' ? ' active' : ''}`}
                        onClick={() => setTab('invitations')}
                    >
                        Invitations
                    </button>
                    <button
                        className={`tab${tab === 'deliveries' ? ' active' : ''}`}
                        onClick={() => setTab('deliveries')}
                    >
                        Deliveries
                    </button>
                </div>

                {tab === 'guests' ? (
                    <GuestsPanel
                        eventId={eventId}
                        eventTitle={event?.title}
                        checkInEnabled={isCapabilityEnabled(event, 'check_in')}
                    />
                ) : null}

                {tab === 'invitations' ? (
                    <div className="stack">
                        <div className="row">
                            <button className="btn btn-primary" disabled={busy} onClick={sendInvitations}>
                                Send invitations
                            </button>
                            <button className="btn btn-ghost" onClick={loadPreview}>
                                Refresh preview
                            </button>
                        </div>
                        {preview ? (
                            <div className="stack">
                                {preview.mail ? (
                                    <div className="card">
                                        <strong>Email</strong>
                                        <div className="muted">Subject: {preview.mail.subject}</div>
                                        <div
                                            className="card"
                                            style={{ marginTop: '0.5rem', maxHeight: 420, overflow: 'auto', background: '#fff' }}
                                            // Trusted, server-rendered notification email HTML (same as delivery).
                                            dangerouslySetInnerHTML={{ __html: preview.mail.html ?? '' }}
                                        />
                                        {preview.mail.text ? (
                                            <details style={{ marginTop: '0.5rem' }}>
                                                <summary className="muted small">Plain-text version</summary>
                                                <p style={{ whiteSpace: 'pre-wrap', margin: '0.5rem 0' }}>{preview.mail.text}</p>
                                            </details>
                                        ) : null}
                                    </div>
                                ) : null}
                                {preview.sms ? (
                                    <div className="card">
                                        <strong>SMS</strong>
                                        <div className="muted" style={{ whiteSpace: 'pre-wrap' }}>{preview.sms.message}</div>
                                    </div>
                                ) : null}
                                {preview.whatsapp ? (
                                    <div className="card">
                                        <strong>WhatsApp</strong>
                                        <div className="muted" style={{ whiteSpace: 'pre-wrap' }}>{preview.whatsapp.message}</div>
                                    </div>
                                ) : null}
                            </div>
                        ) : (
                            <p className="muted">No preview available.</p>
                        )}
                    </div>
                ) : null}

                {tab === 'deliveries' ? (
                    <div className="stack">
                        <div className="row">
                            <button className="btn btn-ghost" onClick={reloadFailed}>
                                Refresh
                            </button>
                            <button className="btn btn-primary" disabled={busy || failed.length === 0} onClick={retryAll}>
                                Retry all failed
                            </button>
                        </div>
                        {failed.length === 0 ? (
                            <p className="muted">No failed deliveries.</p>
                        ) : (
                            <div className="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Guest</th>
                                            <th>Kind</th>
                                            <th>Channel</th>
                                            <th>Error</th>
                                            <th style={{ textAlign: 'right' }}>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {failed.map((d) => (
                                            <tr key={d.id}>
                                                <td>{d.guest?.name ?? '—'}</td>
                                                <td className="muted">{d.kind}</td>
                                                <td className="muted">{d.channel}</td>
                                                <td className="muted">{d.error ?? '—'}</td>
                                                <td style={{ textAlign: 'right' }}>
                                                    <button className="btn btn-sm btn-ghost" onClick={() => retry(d)}>
                                                        Retry
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {failedPage && (failedPage.total ?? 0) > 0 ? (
                            <div className="row between pagination" aria-label="Failed deliveries pagination">
                                <div className="row" style={{ gap: '0.75rem', alignItems: 'center' }}>
                                    <span className="muted small">
                                        Page {failedPage.current_page} of {failedPage.last_page} · {failedPage.total ?? 0} failed
                                    </span>
                                    <label
                                        className="muted small"
                                        htmlFor="failed-page-size"
                                        style={{ display: 'inline-flex', gap: '0.35rem', alignItems: 'center' }}
                                    >
                                        Rows
                                        <select
                                            id="failed-page-size"
                                            className="select"
                                            style={{ width: 'auto' }}
                                            value={failedPageSize}
                                            onChange={(e) => changeFailedPageSize(Number(e.target.value))}
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
                                        disabled={!failedPage.prev_page_url}
                                        onClick={() => void loadFailed(failedPage.current_page - 1, failedPageSize)}
                                    >
                                        Previous
                                    </button>
                                    <button
                                        type="button"
                                        className="btn btn-sm btn-ghost"
                                        disabled={!failedPage.next_page_url}
                                        onClick={() => void loadFailed(failedPage.current_page + 1, failedPageSize)}
                                    >
                                        Next
                                    </button>
                                </div>
                            </div>
                        ) : null}
                    </div>
                ) : null}
            </div>
        </div>
    );
}
