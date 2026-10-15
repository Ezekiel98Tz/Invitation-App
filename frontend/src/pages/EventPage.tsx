import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, apiErrorMessage } from '../lib/api';
import type { Delivery, EventItem, Paginated } from '../lib/types';
import { Alert, LoadingBlock } from '../components/ui';
import { GuestsPanel } from '../components/GuestsPanel';

type Tab = 'guests' | 'invitations' | 'deliveries';

interface Preview {
    mail?: { subject: string; lines: string[] };
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

    const notify = (message: string) => {
        setToast(message);
        window.setTimeout(() => setToast(null), 3500);
    };

    const loadEvent = useCallback(async () => {
        try {
            const { data } = await api.get<EventItem>(`/api/v1/events/${eventId}`);
            setEvent(data);
        } catch (err) {
            setError(apiErrorMessage(err));
        }
    }, [eventId]);

    const loadPreview = useCallback(async () => {
        try {
            const { data } = await api.get<{ channels: Preview }>(`/api/v1/events/${eventId}/invitations/preview`);
            setPreview(data.channels);
        } catch {
            // preview is best-effort
        }
    }, [eventId]);

    const loadFailed = useCallback(async () => {
        try {
            const { data } = await api.get<Paginated<Delivery>>(`/api/v1/events/${eventId}/deliveries/failed`);
            setFailed(data.data);
        } catch (err) {
            setError(apiErrorMessage(err));
        }
    }, [eventId]);

    useEffect(() => {
        void loadEvent();
        void loadPreview();
        void loadFailed();
    }, [loadEvent, loadPreview, loadFailed]);

    const sendInvitations = async () => {
        if (!window.confirm('Send invitations to all unsent guests?')) return;
        setBusy(true);
        try {
            const { data } = await api.post<{ queued: number }>(`/api/v1/events/${eventId}/invitations/send`);
            notify(`Invitations queued: ${data.queued}`);
            await loadFailed();
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
            await loadFailed();
        } catch (err) {
            setError(apiErrorMessage(err));
        }
    };

    const retryAll = async () => {
        setBusy(true);
        try {
            const { data } = await api.post<{ queued: number }>(`/api/v1/events/${eventId}/deliveries/retry-failed`);
            notify(`Queued: ${data.queued}`);
            await loadFailed();
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setBusy(false);
        }
    };

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
                <Link className="btn btn-primary" to={`/events/${eventId}/invitation`}>
                    Invitation design
                </Link>
            </div>

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

                {tab === 'guests' ? <GuestsPanel eventId={eventId} eventTitle={event?.title} /> : null}

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
                                        <div className="muted">{preview.mail.subject}</div>
                                        <ul>
                                            {preview.mail.lines.map((line) => (
                                                <li key={line}>{line}</li>
                                            ))}
                                        </ul>
                                    </div>
                                ) : null}
                                {preview.sms ? (
                                    <div className="card">
                                        <strong>SMS</strong>
                                        <div className="muted">{preview.sms.message}</div>
                                    </div>
                                ) : null}
                                {preview.whatsapp ? (
                                    <div className="card">
                                        <strong>WhatsApp</strong>
                                        <div className="muted">{preview.whatsapp.message}</div>
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
                            <button className="btn btn-ghost" onClick={loadFailed}>
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
                    </div>
                ) : null}
            </div>
        </div>
    );
}
