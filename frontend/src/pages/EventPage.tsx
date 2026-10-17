import { useCallback, useEffect, useState } from 'react';
import { Link, useLocation, useNavigate, useOutletContext, useParams } from 'react-router-dom';
import { api, apiErrorMessage } from '../lib/api';
import type { Delivery, EventDeliveryPage, EventItem, Paginated } from '../lib/types';
import { isCapabilityEnabled } from '../lib/capabilities';
import { Alert, LoadingBlock, PageHeading, StatusBadge } from '../components/ui';
import { GuestsPanel } from '../components/GuestsPanel';
import { EventFormModal } from '../components/EventFormModal';
import { CommunicationDialog } from '../components/CommunicationDialog';
import { useRequestGuard } from '../lib/useRequestGuard';
import type { WorkspaceLayoutContext } from '../components/Layout';

type Tab = 'guests' | 'invitations' | 'deliveries';

function tabFromHash(hash: string): Tab | null {
    if (hash === '#guests' || hash === '#invitations' || hash === '#deliveries') return hash.slice(1) as Tab;
    return null;
}

const PAGE_SIZE_OPTIONS = [20, 50, 100] as const;

function formatEventDate(event: EventItem): string {
    if (!event.event_start) return 'Date not set';
    const date = new Date(event.event_start);
    if (Number.isNaN(date.getTime())) return event.event_start;
    try {
        return new Intl.DateTimeFormat('en-GB', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            timeZone: event.timezone || undefined,
        }).format(date);
    } catch {
        return date.toLocaleString();
    }
}

function formatDeliveryDate(iso: string | null | undefined): string {
    if (!iso) return 'Time not recorded';
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? iso : date.toLocaleString();
}

function labelFromKey(value: string): string {
    return value.replace(/[_-]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function retryOf(delivery: Delivery): number | null {
    const value = delivery.meta?.retry_of;

    return typeof value === 'number' ? value : null;
}

interface Preview {
    mail?: { subject: string; html?: string; text?: string };
    sms?: { message: string };
    whatsapp?: { message: string };
}

export function EventPage() {
    const { id } = useParams<{ id: string }>();
    const eventId = id ?? '';
    const location = useLocation();
    const navigate = useNavigate();
    const { updateEventContext } = useOutletContext<WorkspaceLayoutContext>();
    const [event, setEvent] = useState<EventItem | null>(null);
    const [tab, setTab] = useState<Tab>(() => tabFromHash(location.hash) ?? 'guests');
    const [eventLoading, setEventLoading] = useState(true);
    const [eventError, setEventError] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [toast, setToast] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [deliveryView, setDeliveryView] = useState<'history' | 'failed'>('history');
    const [deliveryHistory, setDeliveryHistory] = useState<EventDeliveryPage | null>(null);
    const [deliveryHistoryLoading, setDeliveryHistoryLoading] = useState(false);
    const [deliveryHistoryError, setDeliveryHistoryError] = useState<string | null>(null);
    const [failed, setFailed] = useState<Delivery[]>([]);
    const [failedPage, setFailedPage] = useState<Paginated<Delivery> | null>(null);
    const [failedLoading, setFailedLoading] = useState(false);
    const [failedError, setFailedError] = useState<string | null>(null);
    const [deliveryPageSize, setDeliveryPageSize] = useState<number>(20);
    const [editOpen, setEditOpen] = useState(false);
    const [communicationOpen, setCommunicationOpen] = useState(false);
    const [retryingId, setRetryingId] = useState<number | null>(null);

    const eventGuard = useRequestGuard();
    const previewGuard = useRequestGuard();
    const deliveriesGuard = useRequestGuard();
    const failedGuard = useRequestGuard();

    const notify = (message: string) => {
        setToast(message);
        window.setTimeout(() => setToast(null), 3500);
    };

    const loadEvent = useCallback(async () => {
        const { signal, isCurrent } = eventGuard();
        setEventLoading(true);
        setEventError(null);
        try {
            const { data } = await api.get<EventItem>(`/api/v1/events/${eventId}`, { signal });
            if (!isCurrent()) return;
            setEvent(data);
            updateEventContext(data);
        } catch (err) {
            if (signal.aborted || !isCurrent()) return;
            setEventError(apiErrorMessage(err));
        } finally {
            if (isCurrent()) setEventLoading(false);
        }
    }, [eventGuard, eventId, updateEventContext]);

    const loadPreview = useCallback(async () => {
        const { signal, isCurrent } = previewGuard();
        setPreviewLoading(true);
        setPreviewError(null);
        try {
            const { data } = await api.get<{ channels: Preview }>(`/api/v1/events/${eventId}/invitations/preview`, {
                signal,
            });
            if (!isCurrent()) return;
            setPreview(data.channels);
        } catch {
            if (!signal.aborted && isCurrent()) setPreviewError('The invitation preview could not be loaded.');
        } finally {
            if (isCurrent()) setPreviewLoading(false);
        }
    }, [previewGuard, eventId]);

    const loadDeliveryHistory = useCallback(async (page: number, size: number) => {
        const { signal, isCurrent } = deliveriesGuard();
        setDeliveryHistoryLoading(true);
        setDeliveryHistoryError(null);
        try {
            const { data } = await api.get<EventDeliveryPage>(`/api/v1/events/${eventId}/deliveries`, {
                params: { page, per_page: size },
                signal,
            });
            if (!isCurrent()) return;
            setDeliveryHistory(data);
        } catch (err) {
            if (signal.aborted || !isCurrent()) return;
            setDeliveryHistoryError(apiErrorMessage(err));
        } finally {
            if (isCurrent()) setDeliveryHistoryLoading(false);
        }
    }, [deliveriesGuard, eventId]);

    const loadFailed = useCallback(
        async (page: number, size: number) => {
            const { signal, isCurrent } = failedGuard();
            setFailedLoading(true);
            setFailedError(null);
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
                setFailedError(apiErrorMessage(err));
            } finally {
                if (isCurrent()) setFailedLoading(false);
            }
        },
        [failedGuard, eventId],
    );

    const loadDeliveryView = useCallback((page = 1, size = deliveryPageSize, view = deliveryView) => {
        if (view === 'failed') {
            void loadFailed(page, size);
        } else {
            void loadDeliveryHistory(page, size);
        }
    }, [deliveryPageSize, deliveryView, loadDeliveryHistory, loadFailed]);

    const reloadDeliveryView = () => {
        const page = deliveryView === 'failed'
            ? failedPage?.current_page ?? 1
            : deliveryHistory?.current_page ?? 1;
        loadDeliveryView(page, deliveryPageSize, deliveryView);
    };

    const changeDeliveryPageSize = (size: number) => {
        setDeliveryPageSize(size);
        loadDeliveryView(1, size, deliveryView);
    };

    useEffect(() => {
        void loadEvent();
        void loadPreview();
    }, [loadEvent, loadPreview]);

    useEffect(() => {
        if (tab === 'deliveries') loadDeliveryView(1, deliveryPageSize, deliveryView);
    }, [tab, eventId, deliveryPageSize, deliveryView, loadDeliveryView]);

    useEffect(() => {
        setTab(tabFromHash(location.hash) ?? 'guests');
    }, [eventId, location.hash]);

    const selectTab = (next: Tab) => {
        setTab(next);
        const hash = `#${next}`;
        if (location.hash !== hash) {
            navigate(`${location.pathname}${location.search}${hash}`, { replace: true });
        }
    };

    const onTabKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
        const tabs: Tab[] = ['guests', 'invitations', 'deliveries'];
        const currentIndex = tabs.indexOf(tab);
        let nextIndex: number | null = null;
        if (event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % tabs.length;
        if (event.key === 'ArrowLeft') nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
        if (event.key === 'Home') nextIndex = 0;
        if (event.key === 'End') nextIndex = tabs.length - 1;
        if (nextIndex !== null) {
            event.preventDefault();
            selectTab(tabs[nextIndex]);
            document.getElementById(`event-tab-${tabs[nextIndex]}`)?.focus();
        }
    };

    const retry = async (delivery: Delivery) => {
        setRetryingId(delivery.id);
        try {
            await api.post(`/api/v1/events/${eventId}/deliveries/${delivery.id}/retry`);
            notify('Retry queued.');
            reloadDeliveryView();
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setRetryingId(null);
        }
    };

    const retryAll = async () => {
        setBusy(true);
        try {
            const { data } = await api.post<{ queued: number; rejected?: number }>(
                `/api/v1/events/${eventId}/deliveries/retry-failed`,
            );
            notify(
                data.rejected
                    ? `Queued ${data.queued} retries. ${data.rejected} could not be retried.`
                    : `Queued ${data.queued} retries.`,
            );
            reloadDeliveryView();
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    const contributionsEnabled = isCapabilityEnabled(event, 'contributions');
    const checkInEnabled = isCapabilityEnabled(event, 'check_in');

    if (eventLoading && !event) return <LoadingBlock label="Loading event workspace…" />;
    if (!event) {
        return (
            <section className="page-state page-state-error" aria-label="Event workspace could not be loaded">
                <Alert kind="error">{eventError ?? 'Unable to load this event.'}</Alert>
                <button type="button" className="btn btn-ghost" onClick={() => void loadEvent()}>
                    Try again
                </button>
                <Link to="/dashboard" className="muted">Back to events</Link>
            </section>
        );
    }

    const deliveryLoading = deliveryView === 'failed' ? failedLoading : deliveryHistoryLoading;
    const deliveryError = deliveryView === 'failed' ? failedError : deliveryHistoryError;
    const deliveryPage = deliveryView === 'failed' ? failedPage : deliveryHistory;

    return (
        <div className="stack event-page">
            <PageHeading
                title={event.title}
                description={
                    <span className="event-heading-meta">
                        <span>{formatEventDate(event)}</span>
                        <span>{event.venue || 'Venue not set'}</span>
                        {event.celebration ? (
                            <Link to={`/celebrations/${event.celebration.id}`}>
                                Part of {event.celebration.name}
                            </Link>
                        ) : null}
                    </span>
                }
                actions={
                    <div className="row">
                        <button type="button" className="btn btn-ghost" onClick={() => setEditOpen(true)}>
                            Edit event
                        </button>
                        <Link className="btn btn-primary" to={`/events/${eventId}/invitation`}>
                            Invitation design
                        </Link>
                        {!checkInEnabled ? (
                            <Link
                                className="btn btn-ghost btn-sm event-capability-link"
                                to={`/events/${eventId}/check-in`}
                                title="Check-in is disabled for this event. Open this page to learn how to enable it."
                            >
                                Check-in off
                            </Link>
                        ) : null}
                        {!contributionsEnabled ? (
                            <Link
                                className="btn btn-ghost btn-sm event-capability-link"
                                to={`/events/${eventId}/pledges`}
                                title="Contributions are disabled for this event. Open this page to learn how to enable them."
                            >
                                Pledges off
                            </Link>
                        ) : null}
                    </div>
                }
            />

            <EventFormModal
                open={editOpen}
                event={event}
                onClose={() => setEditOpen(false)}
                onSaved={(updatedEvent) => {
                    setEditOpen(false);
                    updateEventContext(updatedEvent);
                    void loadEvent();
                    notify('Event updated.');
                }}
                onError={setError}
            />

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error ? <Alert kind="error">{error}</Alert> : null}
            {eventError ? <Alert kind="error">The latest event details could not be refreshed. {eventError}</Alert> : null}

            <section className="event-workspace" aria-label={`${event.title} workspace`}>
                <div className="tabs" role="tablist" aria-label="Event workspace sections" onKeyDown={onTabKeyDown}>
                    <button
                        id="event-tab-guests"
                        type="button"
                        role="tab"
                        aria-selected={tab === 'guests'}
                        aria-controls="event-panel-guests"
                        tabIndex={tab === 'guests' ? 0 : -1}
                        className={`tab${tab === 'guests' ? ' active' : ''}`}
                        onClick={() => selectTab('guests')}
                    >
                        Guests
                    </button>
                    <button
                        id="event-tab-invitations"
                        type="button"
                        role="tab"
                        aria-selected={tab === 'invitations'}
                        aria-controls="event-panel-invitations"
                        tabIndex={tab === 'invitations' ? 0 : -1}
                        className={`tab${tab === 'invitations' ? ' active' : ''}`}
                        onClick={() => selectTab('invitations')}
                    >
                        Invitations
                    </button>
                    <button
                        id="event-tab-deliveries"
                        type="button"
                        role="tab"
                        aria-selected={tab === 'deliveries'}
                        aria-controls="event-panel-deliveries"
                        tabIndex={tab === 'deliveries' ? 0 : -1}
                        className={`tab${tab === 'deliveries' ? ' active' : ''}`}
                        onClick={() => selectTab('deliveries')}
                    >
                        Deliveries
                    </button>
                </div>

                <div
                    className="event-tab-panel"
                    id="event-panel-guests"
                    role="tabpanel"
                    aria-labelledby="event-tab-guests"
                    tabIndex={tab === 'guests' ? 0 : -1}
                    hidden={tab !== 'guests'}
                >
                    {tab === 'guests' ? (
                        <GuestsPanel eventId={eventId} eventTitle={event.title} checkInEnabled={checkInEnabled} />
                    ) : null}
                </div>

                <div
                    className="stack event-tab-panel"
                    id="event-panel-invitations"
                    role="tabpanel"
                    aria-labelledby="event-tab-invitations"
                    tabIndex={tab === 'invitations' ? 0 : -1}
                    hidden={tab !== 'invitations'}
                >
                    {tab === 'invitations' ? (
                        <>
                        <section className="invitation-actions" aria-labelledby="invitation-queue-title">
                            <div>
                                <h2 id="invitation-queue-title">Queue invitations</h2>
                                <p className="muted">
                                    Choose a channel and review the scope before queueing. A provider accepting a message is not confirmation that it was delivered.
                                </p>
                            </div>
                            <div className="row">
                                <button className="btn btn-primary" onClick={() => setCommunicationOpen(true)}>
                                    Review and queue invitations
                                </button>
                                <button type="button" className="btn btn-ghost" onClick={() => selectTab('guests')}>
                                    Select specific guests
                                </button>
                                <button className="btn btn-ghost" onClick={() => void loadPreview()} disabled={previewLoading}>
                                    {previewLoading ? 'Refreshing…' : 'Refresh preview'}
                                </button>
                            </div>
                        </section>

                        <section className="invitation-preview" aria-labelledby="invitation-preview-title">
                            <div className="section-head">
                                <div>
                                    <h2 id="invitation-preview-title">Invitation preview</h2>
                                    <p className="muted section-sub">Preview uses this event’s current invitation design.</p>
                                </div>
                            </div>
                            {previewError ? <Alert kind="error">{previewError}</Alert> : null}
                            {previewLoading ? (
                                <LoadingBlock label="Loading invitation preview…" />
                            ) : preview ? (
                                <div className="invitation-preview-channels">
                                    {preview.mail ? (
                                        <section className="invitation-preview-channel" aria-labelledby="email-preview-title">
                                            <div className="invitation-preview-channel-head">
                                                <h3 id="email-preview-title">Email</h3>
                                                <p><strong>Subject</strong> · {preview.mail.subject}</p>
                                            </div>
                                            <div
                                                className="email-preview-rendered"
                                                // Trusted, server-rendered notification email HTML (same as delivery).
                                                dangerouslySetInnerHTML={{ __html: preview.mail.html ?? '' }}
                                            />
                                            {preview.mail.text ? (
                                                <details className="invitation-plain-text">
                                                    <summary>View plain-text email</summary>
                                                    <p>{preview.mail.text}</p>
                                                </details>
                                            ) : null}
                                        </section>
                                    ) : null}
                                    {preview.sms ? (
                                        <section className="invitation-preview-channel" aria-labelledby="sms-preview-title">
                                            <h3 id="sms-preview-title">SMS</h3>
                                            <p className="invitation-message-text">{preview.sms.message}</p>
                                        </section>
                                    ) : null}
                                    {preview.whatsapp ? (
                                        <section className="invitation-preview-channel" aria-labelledby="whatsapp-preview-title">
                                            <h3 id="whatsapp-preview-title">WhatsApp</h3>
                                            <p className="invitation-message-text">{preview.whatsapp.message}</p>
                                        </section>
                                    ) : null}
                                </div>
                            ) : previewError ? null : (
                                <p className="muted">No invitation preview is available for this event.</p>
                            )}
                        </section>
                        </>
                    ) : null}
                </div>

                <div
                    className="stack event-tab-panel"
                    id="event-panel-deliveries"
                    role="tabpanel"
                    aria-labelledby="event-tab-deliveries"
                    tabIndex={tab === 'deliveries' ? 0 : -1}
                    hidden={tab !== 'deliveries'}
                >
                    {tab === 'deliveries' ? (
                        <>
                        <header className="delivery-toolbar">
                            <div>
                                <h2>Delivery history</h2>
                                <p className="muted">
                                    Guest groups are ordered by their latest attempt, newest first. Queued means waiting to send; provider accepted means the channel accepted the request; only Delivered is confirmed delivery.
                                </p>
                            </div>
                            <div className="row delivery-toolbar-actions">
                                <div className="delivery-view-field">
                                    <label className="label" htmlFor="delivery-view">Show</label>
                                    <select
                                        id="delivery-view"
                                        className="select"
                                        value={deliveryView}
                                        onChange={(event) => setDeliveryView(event.target.value as 'history' | 'failed')}
                                    >
                                        <option value="history">All guest history</option>
                                        <option value="failed">Failed only</option>
                                    </select>
                                </div>
                                <button type="button" className="btn btn-ghost" onClick={reloadDeliveryView} disabled={deliveryLoading}>
                                    Refresh
                                </button>
                                {deliveryView === 'failed' ? (
                                    <button
                                        type="button"
                                        className="btn btn-primary"
                                        disabled={busy || retryingId !== null || failed.length === 0}
                                        onClick={retryAll}
                                    >
                                        {busy ? 'Queuing…' : 'Retry failed deliveries'}
                                    </button>
                                ) : null}
                            </div>
                        </header>
                        {deliveryView === 'failed' ? (
                            <p className="delivery-bulk-note muted small">Bulk retry queues up to 500 failed deliveries per request.</p>
                        ) : null}

                        {deliveryLoading ? (
                            <LoadingBlock label={deliveryView === 'failed' ? 'Loading failed deliveries…' : 'Loading delivery history…'} />
                        ) : deliveryError ? (
                            <section className="page-state page-state-error" aria-label="Delivery history could not be loaded">
                                <Alert kind="error">{deliveryError}</Alert>
                                <button type="button" className="btn btn-ghost" onClick={reloadDeliveryView}>
                                    Try again
                                </button>
                            </section>
                        ) : deliveryView === 'history' ? (
                            <>
                                {!deliveryHistory || deliveryHistory.total === 0 ? (
                                    <section className="page-state">
                                        <h3>No guests in this event yet</h3>
                                        <p className="muted">Add guests before invitation and delivery history can appear.</p>
                                        <button type="button" className="btn btn-ghost" onClick={() => selectTab('guests')}>
                                            Go to guests
                                        </button>
                                    </section>
                                ) : deliveryHistory.data.every((guest) => guest.deliveries.length === 0) ? (
                                    <section className="page-state">
                                        <h3>No delivery attempts yet</h3>
                                        <p className="muted">History appears after an invitation or reminder enters the delivery workflow.</p>
                                        <button type="button" className="btn btn-ghost" onClick={() => selectTab('invitations')}>
                                            Review invitations
                                        </button>
                                    </section>
                                ) : (
                                    <div className="delivery-guest-history">
                                        {deliveryHistory.data.filter((guest) => guest.deliveries.length > 0).map((guest) => (
                                            <section className="delivery-guest-record" key={guest.id} aria-label={`Delivery history for ${guest.name}`}>
                                                <header className="delivery-guest-heading">
                                                    <h3>{guest.name}</h3>
                                                    <p className="muted">{guest.email || guest.phone || 'No contact detail'}</p>
                                                </header>
                                                <div className="delivery-attempt-list">
                                                    {guest.deliveries.map((delivery) => {
                                                        const timestamp = delivery.delivered_at ?? delivery.sent_at ?? delivery.failed_at ?? delivery.created_at;
                                                        const timestampLabel = delivery.status === 'delivered'
                                                            ? 'Delivered'
                                                            : delivery.status === 'sent'
                                                              ? 'Accepted by provider'
                                                              : delivery.status === 'failed'
                                                                ? 'Failed'
                                                                : 'Queued';
                                                        return (
                                                            <article className="delivery-attempt" key={delivery.id}>
                                                                <div className="delivery-attempt-summary">
                                                                    <strong>{labelFromKey(delivery.kind)}</strong>
                                                                    <span>{labelFromKey(delivery.channel)}</span>
                                                                    <StatusBadge status={delivery.status} />
                                                                    {retryOf(delivery) !== null ? (
                                                                        <span
                                                                            className="row-tag"
                                                                            title={`Retry of delivery #${retryOf(delivery)}`}
                                                                        >
                                                                            retry attempt
                                                                        </span>
                                                                    ) : null}
                                                                </div>
                                                                <div className="delivery-attempt-detail">
                                                                    {timestamp ? <span>{timestampLabel} · {formatDeliveryDate(timestamp)}</span> : <span>{timestampLabel}</span>}
                                                                    {delivery.error ? <p className="delivery-error">{delivery.error}</p> : null}
                                                                </div>
                                                                {delivery.status === 'failed' ? (
                                                                    <button
                                                                        type="button"
                                                                        className="btn btn-sm btn-ghost"
                                                                        disabled={retryingId === delivery.id}
                                                                        onClick={() => void retry(delivery)}
                                                                    >
                                                                        {retryingId === delivery.id ? 'Queuing…' : 'Retry'}
                                                                    </button>
                                                                ) : null}
                                                            </article>
                                                        );
                                                    })}
                                                </div>
                                            </section>
                                        ))}
                                    </div>
                                )}
                            </>
                        ) : failed.length === 0 ? (
                            <section className="page-state">
                                <h3>No failed deliveries</h3>
                                <p className="muted">There are no failed delivery attempts to retry for this event.</p>
                            </section>
                        ) : (
                            <div className="delivery-failure-list">
                                {failed.map((delivery) => (
                                    <article className="delivery-attempt delivery-attempt--failed" key={delivery.id}>
                                        <div className="delivery-attempt-guest">
                                            <strong>{delivery.guest?.name ?? 'Guest unavailable'}</strong>
                                            <span className="muted">{delivery.guest?.email || delivery.guest?.phone || 'No contact detail'}</span>
                                        </div>
                                        <div className="delivery-attempt-summary">
                                            <strong>{labelFromKey(delivery.kind)}</strong>
                                            <span>{labelFromKey(delivery.channel)}</span>
                                            <StatusBadge status={delivery.status} />
                                        </div>
                                        <div className="delivery-attempt-detail">
                                            <strong>Reason</strong>
                                            <p className="delivery-error">{delivery.error || 'No failure detail provided.'}</p>
                                        </div>
                                        <button
                                            type="button"
                                            className="btn btn-sm btn-ghost"
                                            disabled={retryingId === delivery.id}
                                            onClick={() => void retry(delivery)}
                                        >
                                            {retryingId === delivery.id ? 'Queuing…' : 'Retry'}
                                        </button>
                                    </article>
                                ))}
                            </div>
                        )}

                        {deliveryPage && (deliveryPage.total ?? 0) > 0 ? (
                            <nav className="row between pagination delivery-pagination" aria-label={deliveryView === 'failed' ? 'Failed deliveries pagination' : 'Delivery history guest pagination'}>
                                <div className="row delivery-pagination-meta">
                                    <span className="muted small">
                                        {deliveryView === 'failed'
                                            ? `Page ${failedPage?.current_page ?? 1} of ${failedPage?.last_page ?? 1} · ${failedPage?.total ?? 0} failed deliveries`
                                            : `Guest page ${deliveryHistory?.current_page ?? 1} of ${deliveryHistory?.last_page ?? 1} · ${deliveryHistory?.total ?? 0} guests`}
                                    </span>
                                    <label className="muted small delivery-page-size-label" htmlFor="delivery-page-size">
                                        Rows
                                        <select
                                            id="delivery-page-size"
                                            className="select"
                                            value={deliveryPageSize}
                                            onChange={(event) => changeDeliveryPageSize(Number(event.target.value))}
                                            disabled={deliveryLoading}
                                        >
                                            {PAGE_SIZE_OPTIONS.map((size) => <option key={size} value={size}>{size}</option>)}
                                        </select>
                                    </label>
                                </div>
                                <div className="row">
                                    <button
                                        type="button"
                                        className="btn btn-sm btn-ghost"
                                        disabled={!deliveryPage.prev_page_url || deliveryLoading}
                                        onClick={() => loadDeliveryView(deliveryPage.current_page - 1, deliveryPageSize)}
                                    >
                                        Previous
                                    </button>
                                    <button
                                        type="button"
                                        className="btn btn-sm btn-ghost"
                                        disabled={!deliveryPage.next_page_url || deliveryLoading}
                                        onClick={() => loadDeliveryView(deliveryPage.current_page + 1, deliveryPageSize)}
                                    >
                                        Next
                                    </button>
                                </div>
                            </nav>
                        ) : null}
                        </>
                    ) : null}
                </div>
            </section>

            <CommunicationDialog
                open={communicationOpen}
                eventId={eventId}
                kind="invitation"
                scope="all-unsent"
                onClose={() => setCommunicationOpen(false)}
                onCompleted={() => {
                    if (tab === 'deliveries') reloadDeliveryView();
                }}
            />
        </div>
    );
}
