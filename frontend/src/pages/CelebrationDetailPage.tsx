import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, apiErrorMessage } from '../lib/api';
import type { CelebrationDetail, CelebrationPledgeReport, EventItem, Paginated } from '../lib/types';
import { Alert, EmptyState, LoadingBlock, PageHeading } from '../components/ui';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { EventFormModal } from '../components/EventFormModal';
import { PledgeSummary } from '../components/PledgeSummary';
import { formatMoney } from '../lib/money';
import { useRequestGuard } from '../lib/useRequestGuard';

function formatWhen(iso: string | null | undefined, timezone?: string | null): string {
    if (!iso) return 'Date not set';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    try {
        return new Intl.DateTimeFormat('en-GB', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            timeZone: timezone || undefined,
        }).format(d);
    } catch {
        return d.toLocaleString();
    }
}

export function CelebrationDetailPage() {
    const { id } = useParams<{ id: string }>();
    const celebrationId = id ?? '';

    const [detail, setDetail] = useState<CelebrationDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [detailError, setDetailError] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [toast, setToast] = useState<string | null>(null);

    const [createOpen, setCreateOpen] = useState(false);

    const [candidates, setCandidates] = useState<EventItem[]>([]);
    const [candidatesMeta, setCandidatesMeta] = useState<Paginated<EventItem> | null>(null);
    const [candidatesLoading, setCandidatesLoading] = useState(false);
    const [candidatesError, setCandidatesError] = useState<string | null>(null);
    const [candidateSearch, setCandidateSearch] = useState('');
    const [appliedSearch, setAppliedSearch] = useState('');
    const [addBusyId, setAddBusyId] = useState<number | null>(null);

    const [removeTarget, setRemoveTarget] = useState<EventItem | null>(null);
    const [removing, setRemoving] = useState(false);

    const [pledgeReport, setPledgeReport] = useState<CelebrationPledgeReport | null>(null);
    const [pledgeLoading, setPledgeLoading] = useState(true);
    const [pledgeError, setPledgeError] = useState<string | null>(null);

    const flash = (message: string) => {
        setToast(message);
        window.setTimeout(() => setToast(null), 3500);
    };

    const detailGuard = useRequestGuard();
    const candidatesGuard = useRequestGuard();
    const pledgeGuard = useRequestGuard();

    const loadDetail = useCallback(async (page = 1) => {
        const { signal, isCurrent } = detailGuard();
        setLoading(true);
        setDetailError(null);
        try {
            const { data } = await api.get<CelebrationDetail>(`/api/v1/celebrations/${celebrationId}`, {
                params: { page },
                signal,
            });
            if (!isCurrent()) return;
            setDetail(data);
        } catch (err) {
            if (signal.aborted || !isCurrent()) return;
            setDetailError(apiErrorMessage(err));
        } finally {
            if (isCurrent()) setLoading(false);
        }
    }, [detailGuard, celebrationId]);

    // Standalone candidates are fetched from the server (paginated + searchable)
    // so no eligible event is silently omitted when there are many events.
    const loadCandidates = useCallback(async (page = 1, search = '') => {
        const { signal, isCurrent } = candidatesGuard();
        setCandidatesLoading(true);
        setCandidatesError(null);
        try {
            const { data } = await api.get<Paginated<EventItem>>('/api/v1/events', {
                params: { standalone: 1, page, search },
                signal,
            });
            if (!isCurrent()) return;
            setCandidates(data.data);
            setCandidatesMeta(data);
        } catch (err) {
            if (signal.aborted || !isCurrent()) return;
            setCandidatesError(apiErrorMessage(err));
        } finally {
            if (isCurrent()) setCandidatesLoading(false);
        }
    }, [candidatesGuard]);

    const loadPledgeReport = useCallback(async () => {
        const { signal, isCurrent } = pledgeGuard();
        setPledgeLoading(true);
        setPledgeError(null);
        try {
            const { data } = await api.get<CelebrationPledgeReport>(
                `/api/v1/reports/celebrations/${celebrationId}/pledges`,
                { signal },
            );
            if (!isCurrent()) return;
            setPledgeReport(data);
        } catch (err) {
            if (signal.aborted || !isCurrent()) return;
            setPledgeError(apiErrorMessage(err));
        } finally {
            if (isCurrent()) setPledgeLoading(false);
        }
    }, [pledgeGuard, celebrationId]);

    useEffect(() => {
        void loadDetail(1);
        void loadCandidates(1, '');
        void loadPledgeReport();
    }, [loadDetail, loadCandidates, loadPledgeReport]);

    const refresh = async () => {
        await Promise.all([
            loadDetail(detail?.events_meta.current_page ?? 1),
            loadCandidates(candidatesMeta?.current_page ?? 1, appliedSearch),
            loadPledgeReport(),
        ]);
    };

    const applySearch = () => {
        setAppliedSearch(candidateSearch);
        void loadCandidates(1, candidateSearch);
    };

    const addEvent = async (event: EventItem) => {
        setAddBusyId(event.id);
        try {
            await api.post(`/api/v1/celebrations/${celebrationId}/events/${event.id}`);
            flash(`“${event.title}” added to this celebration.`);
            await Promise.all([loadDetail(detail?.events_meta.current_page ?? 1), loadCandidates(1, appliedSearch)]);
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setAddBusyId(null);
        }
    };

    const detach = async () => {
        if (!removeTarget) return;
        setRemoving(true);
        try {
            await api.delete(`/api/v1/celebrations/${celebrationId}/events/${removeTarget.id}`);
            setRemoveTarget(null);
            flash('Event removed from the celebration. The event and its guests were kept.');
            await refresh();
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setRemoving(false);
        }
    };

    const handleCreated = async () => {
        setCreateOpen(false);
        flash('Event created in this celebration.');
        await refresh();
    };

    if (loading) return <LoadingBlock label="Loading celebration…" />;
    if (!detail) {
        return (
            <section className="page-state page-state-error" aria-label="Celebration could not be loaded">
                <Alert kind="error">{detailError ?? 'Unable to load this celebration.'}</Alert>
                <button type="button" className="btn btn-ghost" onClick={() => void loadDetail(1)}>
                    Try again
                </button>
                <Link to="/celebrations" className="muted">Back to celebrations</Link>
            </section>
        );
    }

    const events = detail.events;
    const hasSearch = appliedSearch !== '';

    return (
        <div className="stack celebration-detail-page">
            <Link to="/celebrations" className="detail-back-link">Back to celebrations</Link>
            <PageHeading
                title={detail.celebration.name}
                description={
                    <span className="celebration-heading-description">
                        {detail.celebration.description || 'A grouping for related occasions.'}
                        <span>Events remain independent: each has its own date, venue, guests, invitation, and RSVP.</span>
                    </span>
                }
                actions={
                    <button type="button" className="btn btn-primary" onClick={() => setCreateOpen(true)}>
                        Create event here
                    </button>
                }
            />

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error ? <Alert kind="error">{error}</Alert> : null}

            <section className="celebration-events-section" aria-labelledby="celebration-events-title">
                <header className="section-head">
                    <div>
                        <h2 id="celebration-events-title">Events in this celebration</h2>
                        <p className="muted section-sub">
                            {detail.events_meta.total} event{detail.events_meta.total === 1 ? '' : 's'} · event details stay separate
                        </p>
                    </div>
                </header>
                {events.length === 0 ? (
                    <EmptyState
                        title="No events in this celebration yet"
                        description="Create an event directly in this group, or add an existing standalone event below."
                        action={
                            <div className="row empty-state-actions-row">
                                <button type="button" className="btn btn-primary" onClick={() => setCreateOpen(true)}>
                                    Create event here
                                </button>
                                <a className="btn btn-ghost" href="#standalone-events">
                                    Add an existing event
                                </a>
                            </div>
                        }
                    />
                ) : (
                    <div className="celebration-event-list">
                        {events.map((event) => (
                            <article className="celebration-event-row" key={event.id}>
                                <div className="celebration-event-copy">
                                    <Link className="celebration-event-title" to={`/events/${event.id}`}>
                                        {event.title}
                                    </Link>
                                    <div className="celebration-event-meta">
                                        {event.event_type ? <span>{event.event_type.replace(/[_-]+/g, ' ')}</span> : null}
                                        <span>{formatWhen(event.event_start, event.timezone)}</span>
                                        <span>{event.venue || 'Venue not set'}</span>
                                    </div>
                                </div>
                                <div className="row celebration-event-actions">
                                    <Link className="btn btn-ghost btn-sm" to={`/events/${event.id}`}>
                                        Manage event
                                    </Link>
                                    <button
                                        type="button"
                                        className="btn btn-ghost btn-sm"
                                        onClick={() => setRemoveTarget(event)}
                                        disabled={removing}
                                    >
                                        Remove from group
                                    </button>
                                </div>
                            </article>
                        ))}
                    </div>
                )}

                {detail.events_meta.total > 0 ? (
                    <nav
                        className="row between pagination"
                        aria-label="Grouped events pagination"
                    >
                        <span className="muted small">
                            Page {detail.events_meta.current_page} of {detail.events_meta.last_page} ·{' '}
                            {detail.events_meta.total} event{detail.events_meta.total === 1 ? '' : 's'}
                        </span>
                        <div className="row">
                            <button
                                type="button"
                                className="btn btn-sm btn-ghost"
                                disabled={!detail.events_meta.prev_page_url || loading}
                                onClick={() => void loadDetail(detail.events_meta.current_page - 1)}
                            >
                                Previous
                            </button>
                            <button
                                type="button"
                                className="btn btn-sm btn-ghost"
                                disabled={!detail.events_meta.next_page_url || loading}
                                onClick={() => void loadDetail(detail.events_meta.current_page + 1)}
                            >
                                Next
                            </button>
                        </div>
                    </nav>
                ) : null}
            </section>

            <section id="standalone-events" className="celebration-candidates-section" aria-labelledby="standalone-events-title">
                <header className="section-head">
                    <div>
                        <h2 id="standalone-events-title">Add an existing event</h2>
                        <p className="muted section-sub">
                            Only standalone events are shown. Adding one changes its group, not its guests, invitation, RSVPs, or delivery history.
                        </p>
                    </div>
                </header>

                <form className="toolbar candidate-search" role="search" onSubmit={(event) => { event.preventDefault(); applySearch(); }}>
                    <div className="search-field">
                        <label className="label" htmlFor="candidate-search">
                            Find a standalone event
                        </label>
                        <input
                            id="candidate-search"
                            className="input"
                            type="search"
                            placeholder="Event title"
                            value={candidateSearch}
                            onChange={(e) => setCandidateSearch(e.target.value)}
                        />
                    </div>
                    <button type="submit" className="btn btn-ghost" disabled={candidatesLoading}>
                        Search
                    </button>
                    {hasSearch ? (
                        <button
                            type="button"
                            className="btn btn-link"
                            disabled={candidatesLoading}
                            onClick={() => {
                                setCandidateSearch('');
                                setAppliedSearch('');
                                void loadCandidates(1, '');
                            }}
                        >
                            Clear search
                        </button>
                    ) : null}
                </form>

                {candidatesLoading ? (
                    <div className="list-loading" aria-label="Loading standalone events" aria-busy="true">
                        <LoadingBlock label="Loading standalone events…" />
                    </div>
                ) : candidatesError ? (
                    <section className="page-state page-state-error" aria-label="Standalone events could not be loaded">
                        <Alert kind="error">{candidatesError}</Alert>
                        <button type="button" className="btn btn-ghost" onClick={() => void loadCandidates(candidatesMeta?.current_page ?? 1, appliedSearch)}>
                            Try again
                        </button>
                    </section>
                ) : candidates.length === 0 ? (
                    <EmptyState
                        title={hasSearch ? 'No standalone events match this search' : 'No standalone events to add'}
                        description={
                            hasSearch
                                ? 'Try another title or clear the search.'
                                : 'Create an event in this celebration or make one from the Events workspace.'
                        }
                        action={
                            hasSearch ? (
                                <button
                                    type="button"
                                    className="btn btn-ghost"
                                    onClick={() => {
                                        setCandidateSearch('');
                                        setAppliedSearch('');
                                        void loadCandidates(1, '');
                                    }}
                                >
                                    Clear search
                                </button>
                            ) : (
                                <button type="button" className="btn btn-primary" onClick={() => setCreateOpen(true)}>
                                    Create event here
                                </button>
                            )
                        }
                    />
                ) : (
                    <div className="celebration-event-list celebration-candidate-list">
                        {candidates.map((event) => (
                            <article className="celebration-event-row" key={event.id}>
                                <div className="celebration-event-copy">
                                    <Link className="celebration-event-title" to={`/events/${event.id}`}>
                                        {event.title}
                                    </Link>
                                    <div className="celebration-event-meta">
                                        {event.event_type ? <span>{event.event_type.replace(/[_-]+/g, ' ')}</span> : null}
                                        <span>{formatWhen(event.event_start, event.timezone)}</span>
                                        <span>{event.venue || 'Venue not set'}</span>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-primary"
                                    onClick={() => addEvent(event)}
                                    disabled={addBusyId !== null}
                                >
                                    {addBusyId === event.id ? 'Adding…' : 'Add to celebration'}
                                </button>
                            </article>
                        ))}
                    </div>
                )}

                {candidatesMeta && candidatesMeta.last_page > 1 ? (
                    <nav className="row between pagination" aria-label="Standalone events pagination">
                        <span className="muted">
                            Page {candidatesMeta.current_page} of {candidatesMeta.last_page} · {candidatesMeta.total ?? 0} standalone event
                            {(candidatesMeta.total ?? 0) === 1 ? '' : 's'}
                        </span>
                        <div className="row">
                            <button
                                type="button"
                                className="btn btn-sm btn-ghost"
                                disabled={!candidatesMeta.prev_page_url || candidatesLoading}
                                onClick={() => loadCandidates(candidatesMeta.current_page - 1, appliedSearch)}
                            >
                                Previous
                            </button>
                            <button
                                type="button"
                                className="btn btn-sm btn-ghost"
                                disabled={!candidatesMeta.next_page_url || candidatesLoading}
                                onClick={() => loadCandidates(candidatesMeta.current_page + 1, appliedSearch)}
                            >
                                Next
                            </button>
                        </div>
                    </nav>
                ) : null}
            </section>

            {pledgeLoading ? (
                <div className="card">
                    <LoadingBlock label="Loading pledge roll-up…" />
                </div>
            ) : pledgeError ? (
                <div className="card">
                    <h3 style={{ marginTop: 0 }}>Pledge roll-up</h3>
                    <p className="muted small" style={{ marginTop: 0 }}>
                        Across the {detail.events.length === 1 ? 'event' : 'events'} in this celebration. Reported
                        amounts only.
                    </p>
                    <Alert kind="error">{pledgeError}</Alert>
                </div>
            ) : pledgeReport ? (
                <PledgeSummary
                    heading="Pledge roll-up"
                    totalPledges={pledgeReport.total_pledges}
                    counts={pledgeReport.counts}
                    currencies={pledgeReport.currencies}
                    methods={pledgeReport.by_method}
                    footer={
                        pledgeReport.events.length > 0 ? (
                            <details style={{ marginTop: '0.85rem' }}>
                                <summary className="muted small" style={{ cursor: 'pointer' }}>
                                    By event ({pledgeReport.event_count})
                                </summary>
                                <div className="table-wrap" style={{ marginTop: '0.5rem' }}>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th scope="col">Event</th>
                                                <th scope="col" className="num">
                                                    Pledges
                                                </th>
                                                <th scope="col" className="num">
                                                    Reported outstanding
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {pledgeReport.events.map((row) => (
                                                <tr key={row.event_id}>
                                                    <td className="cell-strong">
                                                        <Link to={`/events/${row.event_id}/pledges`}>
                                                            {row.title ?? `Event #${row.event_id}`}
                                                        </Link>
                                                    </td>
                                                    <td className="muted num">{row.total_pledges}</td>
                                                    <td className="num">
                                                        {row.currencies.length === 0
                                                            ? '—'
                                                            : row.currencies
                                                                  .map(
                                                                      (total) =>
                                                                          formatMoney(
                                                                              total.outstanding_minor,
                                                                              total.currency,
                                                                          ),
                                                                  )
                                                                  .join(' · ')}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        ) : null
                    }
                />
            ) : null}

            <EventFormModal
                open={createOpen}
                celebrationId={Number(celebrationId)}
                celebrationName={detail.celebration.name}
                onClose={() => setCreateOpen(false)}
                onSaved={handleCreated}
                onError={setError}
            />

            <ConfirmDialog
                open={removeTarget !== null}
                title="Remove event from celebration"
                message={
                    <>
                        Remove <strong>{removeTarget?.title}</strong> from this celebration? The event becomes
                        standalone and keeps its guests, invitation design, RSVPs, and delivery history.
                    </>
                }
                confirmLabel="Remove from celebration"
                busy={removing}
                onConfirm={detach}
                onCancel={() => setRemoveTarget(null)}
            />
        </div>
    );
}
