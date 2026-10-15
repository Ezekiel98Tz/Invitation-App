import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, apiErrorMessage } from '../lib/api';
import type { CelebrationDetail, EventItem, Paginated } from '../lib/types';
import { Alert, LoadingBlock } from '../components/ui';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { EventFormModal } from '../components/EventFormModal';

function formatWhen(iso: string | null | undefined): string {
    if (!iso) return 'Date not set';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleString();
}

export function CelebrationDetailPage() {
    const { id } = useParams<{ id: string }>();
    const celebrationId = id ?? '';

    const [detail, setDetail] = useState<CelebrationDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [toast, setToast] = useState<string | null>(null);

    const [createOpen, setCreateOpen] = useState(false);

    const [candidates, setCandidates] = useState<EventItem[]>([]);
    const [candidatesMeta, setCandidatesMeta] = useState<Paginated<EventItem> | null>(null);
    const [candidatesLoading, setCandidatesLoading] = useState(false);
    const [candidateSearch, setCandidateSearch] = useState('');
    const [appliedSearch, setAppliedSearch] = useState('');
    const [addBusyId, setAddBusyId] = useState<number | null>(null);

    const [removeTarget, setRemoveTarget] = useState<EventItem | null>(null);
    const [removing, setRemoving] = useState(false);

    const flash = (message: string) => {
        setToast(message);
        window.setTimeout(() => setToast(null), 3500);
    };

    const loadDetail = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const { data } = await api.get<CelebrationDetail>(`/api/v1/celebrations/${celebrationId}`);
            setDetail(data);
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setLoading(false);
        }
    }, [celebrationId]);

    // Standalone candidates are fetched from the server (paginated + searchable)
    // so no eligible event is silently omitted when there are many events.
    const loadCandidates = useCallback(async (page = 1, search = '') => {
        setCandidatesLoading(true);
        try {
            const { data } = await api.get<Paginated<EventItem>>('/api/v1/events', {
                params: { standalone: 1, page, search },
            });
            setCandidates(data.data);
            setCandidatesMeta(data);
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setCandidatesLoading(false);
        }
    }, []);

    useEffect(() => {
        void loadDetail();
        void loadCandidates(1, '');
    }, [loadDetail, loadCandidates]);

    const refresh = async () => {
        await Promise.all([loadDetail(), loadCandidates(candidatesMeta?.current_page ?? 1, appliedSearch)]);
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
            await Promise.all([loadDetail(), loadCandidates(1, appliedSearch)]);
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
    if (!detail) return <Alert kind="error">{error ?? 'Unable to load this celebration.'}</Alert>;

    const events = detail.events;
    const hasSearch = appliedSearch !== '';

    return (
        <div className="stack">
            <header className="page-head">
                <div>
                    <Link to="/celebrations" className="muted">
                        ← Back to celebrations
                    </Link>
                    <h1 style={{ marginTop: '0.5rem' }}>{detail.celebration.name}</h1>
                    {detail.celebration.description ? (
                        <p className="muted page-sub">{detail.celebration.description}</p>
                    ) : null}
                </div>
                <button type="button" className="btn btn-primary" onClick={() => setCreateOpen(true)}>
                    Create event in this celebration
                </button>
            </header>

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error ? <Alert kind="error">{error}</Alert> : null}

            <div className="card">
                <h3 style={{ marginTop: 0 }}>Grouped events</h3>
                {events.length === 0 ? (
                    <p className="muted">
                        No events in this celebration yet. Create one above, or add an existing standalone event below.
                    </p>
                ) : (
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Event</th>
                                    <th className="col-hide-sm">When</th>
                                    <th className="col-hide-sm">Venue</th>
                                    <th style={{ textAlign: 'right' }}>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {events.map((event) => (
                                    <tr key={event.id}>
                                        <td className="cell-strong">
                                            <Link to={`/events/${event.id}`}>{event.title}</Link>
                                        </td>
                                        <td className="muted col-hide-sm">{formatWhen(event.event_start)}</td>
                                        <td className="muted col-hide-sm">{event.venue || '—'}</td>
                                        <td style={{ textAlign: 'right' }}>
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-ghost"
                                                onClick={() => setRemoveTarget(event)}
                                                disabled={removing}
                                            >
                                                Remove
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <div className="card">
                <h3 style={{ marginTop: 0 }}>Add an existing standalone event</h3>
                <p className="muted" style={{ marginTop: 0 }}>
                    Only events that are not already part of a celebration are listed. Adding an event does not copy
                    it; its guests, invitation design, RSVPs, and delivery history stay with the event.
                </p>

                <div className="toolbar">
                    <div className="search-field">
                        <label className="label" htmlFor="candidate-search">
                            Search
                        </label>
                        <input
                            id="candidate-search"
                            className="input"
                            type="search"
                            placeholder="Event title"
                            value={candidateSearch}
                            onChange={(e) => setCandidateSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && applySearch()}
                        />
                    </div>
                    <button type="button" className="btn btn-ghost" onClick={applySearch} disabled={candidatesLoading}>
                        Apply
                    </button>
                    {hasSearch ? (
                        <button
                            type="button"
                            className="btn btn-link"
                            onClick={() => {
                                setCandidateSearch('');
                                setAppliedSearch('');
                                void loadCandidates(1, '');
                            }}
                        >
                            Clear
                        </button>
                    ) : null}
                </div>

                {candidatesLoading ? (
                    <LoadingBlock label="Loading events…" />
                ) : candidates.length === 0 ? (
                    <p className="muted" style={{ marginBottom: 0 }}>
                        {hasSearch
                            ? 'No standalone events match this search.'
                            : 'There are no standalone events available to add. Create one above, or from the dashboard.'}
                    </p>
                ) : (
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Event</th>
                                    <th className="col-hide-sm">When</th>
                                    <th className="col-hide-sm">Venue</th>
                                    <th style={{ textAlign: 'right' }}>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {candidates.map((event) => (
                                    <tr key={event.id}>
                                        <td className="cell-strong">
                                            <Link to={`/events/${event.id}`}>{event.title}</Link>
                                        </td>
                                        <td className="muted col-hide-sm">{formatWhen(event.event_start)}</td>
                                        <td className="muted col-hide-sm">{event.venue || '—'}</td>
                                        <td style={{ textAlign: 'right' }}>
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-primary"
                                                onClick={() => addEvent(event)}
                                                disabled={addBusyId !== null}
                                            >
                                                {addBusyId === event.id ? 'Adding…' : 'Add'}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {candidatesMeta && candidatesMeta.last_page > 1 ? (
                    <div className="row between pagination">
                        <span className="muted">
                            Page {candidatesMeta.current_page} of {candidatesMeta.last_page}
                        </span>
                        <div className="row">
                            <button
                                type="button"
                                className="btn btn-sm btn-ghost"
                                disabled={!candidatesMeta.prev_page_url}
                                onClick={() => loadCandidates(candidatesMeta.current_page - 1, appliedSearch)}
                            >
                                Previous
                            </button>
                            <button
                                type="button"
                                className="btn btn-sm btn-ghost"
                                disabled={!candidatesMeta.next_page_url}
                                onClick={() => loadCandidates(candidatesMeta.current_page + 1, appliedSearch)}
                            >
                                Next
                            </button>
                        </div>
                    </div>
                ) : null}
            </div>

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
