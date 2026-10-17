import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api, apiErrorMessage } from '../lib/api';
import type { EventItem, EventReport, Paginated } from '../lib/types';
import { Alert, EmptyState, PageHeading } from '../components/ui';
import { EventCard } from '../components/EventCard';
import { EventFormModal } from '../components/EventFormModal';
import { useRequestGuard } from '../lib/useRequestGuard';

const PAGE_SIZE = 20;

export function DashboardPage() {
    const navigate = useNavigate();
    const [events, setEvents] = useState<EventItem[]>([]);
    const [pagination, setPagination] = useState<Paginated<EventItem> | null>(null);
    const [report, setReport] = useState<EventReport | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [reportError, setReportError] = useState<string | null>(null);
    const [toast, setToast] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const [appliedSearch, setAppliedSearch] = useState('');
    const [modalOpen, setModalOpen] = useState(false);
    const [editingEvent, setEditingEvent] = useState<EventItem | null>(null);

    const beginRequest = useRequestGuard();

    const load = useCallback(async (page: number, term: string) => {
        const { signal, isCurrent } = beginRequest();
        setLoading(true);
        setError(null);
        setReportError(null);

        try {
            const listRes = await api.get<Paginated<EventItem>>('/api/v1/events', {
                params: { page, per_page: PAGE_SIZE, search: term },
                signal,
            });
            if (!isCurrent()) return;

            setEvents(listRes.data.data);
            setPagination(listRes.data);

            // The report endpoint does not accept the event search term. Only
            // pair its summaries with the unfiltered event page that shares its
            // ordering and page size.
            if (term === '') {
                try {
                    const reportRes = await api.get<EventReport>('/api/v1/reports/events', {
                        params: { page, per_page: PAGE_SIZE },
                        signal,
                    });
                    if (!isCurrent()) return;
                    setReport(reportRes.data);
                } catch (err) {
                    if (signal.aborted || !isCurrent()) return;
                    setReport(null);
                    setReportError(apiErrorMessage(err));
                }
            } else {
                setReport(null);
            }
        } catch (err) {
            if (signal.aborted || !isCurrent()) return;
            setError(apiErrorMessage(err));
            setEvents([]);
            setPagination(null);
            setReport(null);
        } finally {
            if (isCurrent()) setLoading(false);
        }
    }, [beginRequest]);

    useEffect(() => {
        void load(1, '');
    }, [load]);

    const statsById = new Map((report?.events ?? []).map((event) => [event.id, event]));

    const flash = (message: string) => {
        setToast(message);
        window.setTimeout(() => setToast(null), 3000);
    };

    const openCreate = () => {
        setEditingEvent(null);
        setModalOpen(true);
    };

    const openEdit = (event: EventItem) => {
        setEditingEvent(event);
        setModalOpen(true);
    };

    const applySearch = (event: React.FormEvent) => {
        event.preventDefault();
        const term = search.trim();
        setAppliedSearch(term);
        void load(1, term);
    };

    const clearSearch = () => {
        setSearch('');
        setAppliedSearch('');
        void load(1, '');
    };

    const handleSaved = async () => {
        const wasEditing = editingEvent !== null;
        setModalOpen(false);
        setEditingEvent(null);
        if (!wasEditing) {
            setSearch('');
            setAppliedSearch('');
            flash('Event created.');
            await load(1, '');
            return;
        }

        flash('Event updated.');
        await load(pagination?.current_page ?? 1, appliedSearch);
    };

    const duplicate = async (event: EventItem) => {
        try {
            await api.post(`/api/v1/events/${event.id}/duplicate`);
            flash('Event duplicated.');
            await load(1, appliedSearch);
        } catch (err) {
            setError(apiErrorMessage(err));
        }
    };

    const destroy = async (event: EventItem) => {
        if (!window.confirm(`Delete "${event.title}"? This cannot be undone.`)) return;
        try {
            await api.delete(`/api/v1/events/${event.id}`);
            flash('Event deleted.');
            const remaining = Math.max(0, (pagination?.total ?? 1) - 1);
            const lastPage = Math.max(1, Math.ceil(remaining / PAGE_SIZE));
            await load(Math.min(pagination?.current_page ?? 1, lastPage), appliedSearch);
        } catch (err) {
            setError(apiErrorMessage(err));
        }
    };

    const retryLoad = () => load(pagination?.current_page ?? 1, appliedSearch);
    const hasSearch = appliedSearch !== '';
    const hasEvents = events.length > 0;
    const initialLoadError = !loading && error !== null && !hasEvents;

    return (
        <div className="stack dashboard-page">
            <PageHeading
                title="Events"
                description="Plan each event, manage its guests, and follow responses from one workspace."
                actions={
                    <button type="button" className="btn btn-primary" onClick={openCreate}>
                        Create event
                    </button>
                }
            />

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error && !initialLoadError ? <Alert kind="error">{error}</Alert> : null}
            {reportError ? (
                <Alert kind="info">RSVP summaries are temporarily unavailable. Event management is still available.</Alert>
            ) : null}

            <form className="toolbar event-search" role="search" onSubmit={applySearch}>
                <div className="search-field">
                    <label className="label" htmlFor="event-search">
                        Search events
                    </label>
                    <input
                        id="event-search"
                        className="input"
                        type="search"
                        placeholder="Event title"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </div>
                <button type="submit" className="btn btn-ghost" disabled={loading}>
                    Search
                </button>
                {hasSearch ? (
                    <button type="button" className="btn btn-link" onClick={clearSearch} disabled={loading}>
                        Clear search
                    </button>
                ) : null}
            </form>

            {loading ? (
                <div className="event-list-loading" aria-label="Loading events" aria-busy="true">
                    {[0, 1, 2].map((item) => (
                        <div className="event-row-skeleton" key={item} aria-hidden="true">
                            <span className="skeleton event-date-skeleton" />
                            <span className="event-row-skeleton-copy">
                                <span className="skeleton" />
                                <span className="skeleton" />
                            </span>
                            <span className="skeleton event-summary-skeleton" />
                        </div>
                    ))}
                </div>
            ) : initialLoadError ? (
                <section className="page-state page-state-error" aria-label="Events could not be loaded">
                    <h2>We couldn’t load your events</h2>
                    <p className="muted">Check your connection, then try again.</p>
                    <button type="button" className="btn btn-ghost" onClick={retryLoad}>
                        Try again
                    </button>
                </section>
            ) : events.length === 0 ? (
                <EmptyState
                    title={hasSearch ? 'No events match that search' : 'Your workspace is ready for its first event'}
                    description={
                        hasSearch
                            ? 'Try another event title or clear your search to see the full list.'
                            : 'Create an event to start building its guest list, invitation, and RSVP workflow.'
                    }
                    action={
                        hasSearch ? (
                            <button type="button" className="btn btn-ghost" onClick={clearSearch}>
                                Clear search
                            </button>
                        ) : (
                            <button type="button" className="btn btn-primary" onClick={openCreate}>
                                Create your first event
                            </button>
                        )
                    }
                />
            ) : (
                <>
                    <div className="event-list" aria-label="Events">
                        {events.map((event) => (
                            <EventCard
                                key={event.id}
                                event={event}
                                summary={statsById.get(event.id)}
                                onEdit={() => openEdit(event)}
                                onDesign={() => navigate(`/events/${event.id}/invitation`)}
                                onDuplicate={() => duplicate(event)}
                                onDelete={() => destroy(event)}
                            />
                        ))}
                    </div>
                    {pagination && pagination.last_page > 1 ? (
                        <nav className="row between pagination event-pagination" aria-label="Events pagination">
                            <span className="muted small">
                                Page {pagination.current_page} of {pagination.last_page} · {pagination.total ?? 0}{' '}
                                {hasSearch ? 'matching ' : ''}event{pagination.total === 1 ? '' : 's'}
                            </span>
                            <div className="row">
                                <button
                                    type="button"
                                    className="btn btn-sm btn-ghost"
                                    disabled={!pagination.prev_page_url || loading}
                                    onClick={() => void load(pagination.current_page - 1, appliedSearch)}
                                >
                                    Previous
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-ghost"
                                    disabled={!pagination.next_page_url || loading}
                                    onClick={() => void load(pagination.current_page + 1, appliedSearch)}
                                >
                                    Next
                                </button>
                            </div>
                        </nav>
                    ) : null}
                </>
            )}

            <EventFormModal
                open={modalOpen}
                event={editingEvent}
                onClose={() => {
                    setModalOpen(false);
                    setEditingEvent(null);
                }}
                onSaved={handleSaved}
                onError={setError}
            />
        </div>
    );
}
