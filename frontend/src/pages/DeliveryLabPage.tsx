import { useCallback, useEffect, useState } from 'react';
import { api, apiErrorMessage, validationErrors } from '../lib/api';
import type {
    DeliveryLabDashboard,
    DeliveryLabDelivery,
    DeliveryLabPreview,
    DeliveryLabTestSendResult,
    EventItem,
    Guest,
    Paginated,
} from '../lib/types';
import { Alert, Field, LoadingBlock, StatusBadge } from '../components/ui';
import { Modal } from '../components/Modal';
import { useRequestGuard } from '../lib/useRequestGuard';

type Channel = 'mail' | 'sms' | 'whatsapp';
type Kind = 'invitation' | 'reminder';

const CHANNEL_LABELS: Record<Channel, string> = { mail: 'Email', sms: 'SMS', whatsapp: 'WhatsApp' };

function formatWhen(iso: string | null | undefined): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleString();
}

function ModeChip({ mode }: { mode: string }) {
    return <span className={`row-tag`}>{mode}</span>;
}

export function DeliveryLabPage() {
    const [dashboard, setDashboard] = useState<DeliveryLabDashboard | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [toast, setToast] = useState<string | null>(null);

    const [events, setEvents] = useState<EventItem[]>([]);
    const [guests, setGuests] = useState<Guest[]>([]);
    const [eventId, setEventId] = useState('');
    const [guestId, setGuestId] = useState('');
    const [kind, setKind] = useState<Kind>('invitation');
    const [channel, setChannel] = useState<Channel>('mail');

    const [preview, setPreview] = useState<DeliveryLabPreview | null>(null);
    const [result, setResult] = useState<DeliveryLabTestSendResult | null>(null);
    const [busy, setBusy] = useState(false);
    const [detail, setDetail] = useState<DeliveryLabDelivery | null>(null);

    const dashboardGuard = useRequestGuard();
    const optionsGuard = useRequestGuard();
    const guestsGuard = useRequestGuard();

    const flash = (message: string) => {
        setToast(message);
        window.setTimeout(() => setToast(null), 3500);
    };

    const loadDashboard = useCallback(async () => {
        const { signal, isCurrent } = dashboardGuard();
        setLoading(true);
        setError(null);
        try {
            const { data } = await api.get<DeliveryLabDashboard>('/api/v1/delivery-lab', { signal });
            if (!isCurrent()) return;
            setDashboard(data);
        } catch (err) {
            if (signal.aborted || !isCurrent()) return;
            setError(apiErrorMessage(err));
        } finally {
            if (isCurrent()) setLoading(false);
        }
    }, [dashboardGuard]);

    useEffect(() => {
        void loadDashboard();
        void (async () => {
            const { signal, isCurrent } = optionsGuard();
            try {
                const { data } = await api.get<Paginated<EventItem>>('/api/v1/events', {
                    params: { per_page: 100 },
                    signal,
                });
                if (!isCurrent()) return;
                setEvents(data.data);
            } catch {
                // non-fatal: the test panel simply has no events
            }
        })();
    }, [loadDashboard, optionsGuard]);

    useEffect(() => {
        setPreview(null);
        setResult(null);
        setGuestId('');
        if (!eventId) {
            setGuests([]);
            return;
        }
        const { signal, isCurrent } = guestsGuard();
        void (async () => {
            try {
                // Fetch a bounded, generous page so every guest is selectable
                // rather than silently truncating at the default page size.
                const { data } = await api.get<Paginated<Guest>>(`/api/v1/events/${eventId}/guests`, {
                    params: { per_page: 100 },
                    signal,
                });
                if (!isCurrent()) return;
                setGuests(data.data);
            } catch (err) {
                if (signal.aborted || !isCurrent()) return;
                setError(apiErrorMessage(err));
            }
        })();
    }, [eventId, guestsGuard]);

    const previewUrl = `/api/v1/events/${eventId}/guests/${guestId}/delivery-lab/preview`;

    const runPreview = async () => {
        if (!eventId || !guestId) return;
        setBusy(true);
        setError(null);
        setResult(null);
        try {
            const { data } = await api.get<DeliveryLabPreview>(previewUrl, { params: { kind, channel } });
            setPreview(data);
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    const runTestSend = async () => {
        if (!eventId || !guestId) return;
        setBusy(true);
        setError(null);
        setResult(null);
        try {
            const { data } = await api.post<DeliveryLabTestSendResult>(
                `/api/v1/events/${eventId}/guests/${guestId}/delivery-lab/test-send`,
                { kind, channel },
            );
            setResult(data);
            flash(`Test send: ${data.outcome}.`);
            await loadDashboard();
        } catch (err: unknown) {
            const response = (err as { response?: { status?: number; data?: unknown } })?.response;
            const data = response?.data as (DeliveryLabTestSendResult & { message?: string }) | undefined;

            if (response?.status === 422 && data?.delivery) {
                setResult({ outcome: data.outcome ?? 'blocked', delivery: data.delivery });
                await loadDashboard();
            } else if (response?.status === 429) {
                setError(data?.message ?? 'A test send was just requested. Please wait a moment.');
            } else {
                const fieldErrors = validationErrors(err);
                setError(
                    Object.keys(fieldErrors).length
                        ? Object.values(fieldErrors)[0]?.[0] ?? apiErrorMessage(err)
                        : apiErrorMessage(err),
                );
            }
        } finally {
            setBusy(false);
        }
    };

    if (loading) return <LoadingBlock label="Loading Delivery Lab…" />;

    const counts = dashboard?.counts;
    const lab = dashboard?.lab;
    const channels = dashboard?.channels ?? {};

    return (
        <div className="stack">
            <header className="page-head">
                <div>
                    <h1>Delivery Lab</h1>
                    <p className="muted page-sub">
                        Inspect channel readiness, preview the exact invitation content, and run safe sandbox test sends.
                    </p>
                </div>
                <button type="button" className="btn btn-ghost" onClick={loadDashboard}>
                    Refresh
                </button>
            </header>

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error ? <Alert kind="error">{error}</Alert> : null}

            {lab ? (
                <div className="card">
                    <div className="row between">
                        <div>
                            <h3 style={{ margin: 0 }}>
                                Mode: {lab.mode} <ModeChip mode={lab.sandbox ? 'sandbox capture' : lab.mode} />
                            </h3>
                            <p className="muted" style={{ margin: '0.35rem 0 0' }}>
                                {lab.enabled
                                    ? `Delivery Lab enabled · ${lab.allowlisted_recipients} allow-listed recipient(s).`
                                    : 'Delivery Lab disabled — test sends are unavailable and normal delivery is unchanged.'}
                            </p>
                        </div>
                    </div>
                    <p className="muted small" style={{ margin: '0.5rem 0 0' }}>
                        Configuration presence is not verified connectivity. “Sandbox” results are captured locally and are
                        never real provider delivery.
                    </p>
                </div>
            ) : null}

            <div className="event-grid">
                {(Object.keys(CHANNEL_LABELS) as Channel[]).map((key) => {
                    const info = channels[key];
                    return (
                        <div key={key} className="card">
                            <div className="row between">
                                <strong>{CHANNEL_LABELS[key]}</strong>
                                <ModeChip mode={info?.mode ?? 'unavailable'} />
                            </div>
                            <p className="muted small" style={{ margin: '0.35rem 0 0' }}>
                                {info?.configured ? 'Configured' : 'Not configured'}
                                {info?.mailer ? ` · mailer: ${info.mailer}` : ''}
                                {' · '}connectivity not verified
                            </p>
                        </div>
                    );
                })}
            </div>

            {counts ? (
                <div className="card">
                    <h3 style={{ marginTop: 0 }}>Delivery counts</h3>
                    <div className="counts">
                        <span className="count"><strong>{counts.queued}</strong> queued</span>
                        <span className="count accepted"><strong>{counts.sent}</strong> accepted/sent</span>
                        <span className="count"><strong>{counts.delivered}</strong> delivered</span>
                        <span className="count declined"><strong>{counts.failed}</strong> failed</span>
                        <span className="count"><strong>{counts.simulated}</strong> simulated</span>
                        <span className="count"><strong>{counts.blocked}</strong> blocked</span>
                    </div>
                </div>
            ) : null}

            <div className="card">
                <h3 style={{ marginTop: 0 }}>Preview &amp; test send</h3>
                <div className="grid-2">
                    <Field label="Event">
                        <select className="select" value={eventId} onChange={(e) => setEventId(e.target.value)}>
                            <option value="">Select an event…</option>
                            {events.map((event) => (
                                <option key={event.id} value={event.id}>{event.title}</option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Guest">
                        <select className="select" value={guestId} onChange={(e) => setGuestId(e.target.value)}>
                            <option value="">Select a guest…</option>
                            {guests.map((guest) => (
                                <option key={guest.id} value={guest.id}>{guest.name}</option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Message kind">
                        <select className="select" value={kind} onChange={(e) => setKind(e.target.value as Kind)}>
                            <option value="invitation">Invitation</option>
                            <option value="reminder">Reminder</option>
                        </select>
                    </Field>
                    <Field label="Channel">
                        <select className="select" value={channel} onChange={(e) => setChannel(e.target.value as Channel)}>
                            {(Object.keys(CHANNEL_LABELS) as Channel[]).map((key) => (
                                <option key={key} value={key}>{CHANNEL_LABELS[key]}</option>
                            ))}
                        </select>
                    </Field>
                </div>

                <div className="row">
                    <button type="button" className="btn btn-ghost" onClick={runPreview} disabled={busy || !guestId}>
                        {busy ? 'Working…' : 'Preview'}
                    </button>
                    <button type="button" className="btn btn-primary" onClick={runTestSend} disabled={busy || !guestId}>
                        Send test
                    </button>
                </div>

                {preview ? (
                    <div className="stack" style={{ marginTop: '1rem' }}>
                        <div className="row between">
                            <strong>Preview · {CHANNEL_LABELS[preview.channel]}</strong>
                            <ModeChip mode={preview.sandbox ? 'sandbox' : 'provider'} />
                        </div>
                        {preview.channel === 'mail' ? (
                            <div className="stack">
                                <div className="muted small">Subject: {preview.content.subject}</div>
                                <div
                                    className="card"
                                    style={{ maxHeight: 360, overflow: 'auto', background: '#fff' }}
                                    // Our own notification mail HTML, rendered for inspection.
                                    dangerouslySetInnerHTML={{ __html: preview.content.html ?? '' }}
                                />
                                {preview.content.text ? (
                                    <details>
                                        <summary className="muted small">Plain-text fallback</summary>
                                        <p style={{ whiteSpace: 'pre-wrap', margin: '0.5rem 0' }}>{preview.content.text}</p>
                                    </details>
                                ) : null}
                            </div>
                        ) : (
                            <div className="card">
                                <div className="muted small">
                                    {preview.channel === 'sms'
                                        ? `SMS · ${preview.content.character_count ?? 0} characters`
                                        : preview.content.template_indication}
                                </div>
                                <p style={{ whiteSpace: 'pre-wrap', margin: '0.5rem 0' }}>{preview.content.message}</p>
                                <a href={preview.content.link} target="_blank" rel="noopener noreferrer" className="muted small">
                                    {preview.content.link}
                                </a>
                            </div>
                        )}
                    </div>
                ) : null}

                {result ? (
                    <div className="stack" style={{ marginTop: '1rem' }}>
                        <Alert kind={result.outcome === 'failed' || result.outcome === 'blocked' ? 'error' : 'success'}>
                            Outcome: <strong>{result.outcome}</strong> · status {result.delivery.status} ·{' '}
                            {result.delivery.simulated ? 'simulated (sandbox)' : 'provider'}
                            {result.delivery.blocked ? ' · blocked' : ''}
                        </Alert>
                        <button type="button" className="btn btn-link" onClick={() => setDetail(result.delivery)}>
                            View delivery record
                        </button>
                    </div>
                ) : null}
            </div>

            <div className="card">
                <h3 style={{ marginTop: 0 }}>Recent delivery activity</h3>
                {!dashboard || dashboard.recent.length === 0 ? (
                    <p className="muted" style={{ marginBottom: 0 }}>No deliveries yet.</p>
                ) : (
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Guest</th>
                                    <th>Kind</th>
                                    <th>Channel</th>
                                    <th>Status</th>
                                    <th>Mode</th>
                                    <th style={{ textAlign: 'right' }}>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {dashboard.recent.map((delivery) => (
                                    <tr key={delivery.id}>
                                        <td className="cell-strong">{delivery.guest?.name ?? '—'}</td>
                                        <td className="muted">{delivery.kind}</td>
                                        <td className="muted">{delivery.channel}</td>
                                        <td>
                                            <StatusBadge status={delivery.blocked ? 'declined' : delivery.status} />
                                        </td>
                                        <td className="muted">
                                            {delivery.mode}
                                            {delivery.simulated ? ' · simulated' : ''}
                                            {delivery.blocked ? ' · blocked' : ''}
                                        </td>
                                        <td style={{ textAlign: 'right' }}>
                                            <button type="button" className="btn btn-sm btn-ghost" onClick={() => setDetail(delivery)}>
                                                Details
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <Modal open={detail !== null} title="Delivery record" onClose={() => setDetail(null)} maxWidth={560}>
                {detail ? (
                    <dl className="details-list">
                        <div><dt>Guest</dt><dd>{detail.guest?.name ?? '—'}</dd></div>
                        <div><dt>Event</dt><dd>{detail.event?.title ?? '—'}</dd></div>
                        <div><dt>Kind</dt><dd>{detail.kind}</dd></div>
                        <div><dt>Channel</dt><dd>{detail.channel}</dd></div>
                        <div><dt>Recipient</dt><dd>{detail.recipient ?? '—'}</dd></div>
                        <div><dt>Status</dt><dd>{detail.status}</dd></div>
                        <div><dt>Mode</dt><dd>{detail.mode}{detail.simulated ? ' (simulated)' : ''}</dd></div>
                        <div><dt>Outcome</dt><dd>{detail.outcome ?? '—'}</dd></div>
                        <div><dt>Provider status</dt><dd>{detail.provider_status ?? '—'}</dd></div>
                        <div><dt>Reference</dt><dd>{detail.provider_reference ?? '—'}</dd></div>
                        <div><dt>Error</dt><dd>{detail.error ?? '—'}</dd></div>
                        <div><dt>Sent</dt><dd>{formatWhen(detail.sent_at)}</dd></div>
                        <div><dt>Delivered</dt><dd>{formatWhen(detail.delivered_at)}</dd></div>
                        <div><dt>Failed</dt><dd>{formatWhen(detail.failed_at)}</dd></div>
                        {detail.captured_message ? (
                            <div>
                                <dt>Captured message</dt>
                                <dd style={{ whiteSpace: 'pre-wrap' }}>{detail.captured_message}</dd>
                            </div>
                        ) : null}
                    </dl>
                ) : null}
                <div className="modal-footer">
                    <button type="button" className="btn btn-ghost" onClick={() => setDetail(null)}>Close</button>
                </div>
            </Modal>
        </div>
    );
}
