import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api, apiErrorMessage } from '../lib/api';
import type { EventItem, EventReport, Paginated } from '../lib/types';
import { Alert, LoadingBlock } from '../components/ui';
import { EventCard } from '../components/EventCard';
import { EventFormModal } from '../components/EventFormModal';
import { useRequestGuard } from '../lib/useRequestGuard';

const PAGE_SIZE = 20;

export function DashboardPage() {
    const navigate = useNavigate();
    const [events, setEvents] = useState<EventItem[]>([]);
    const [report, setReport] = useState<EventReport | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [toast, setToast] = useState<string | null>(null);
    const [modalOpen, setModalOpen] = useState(false);
    const [editingEvent, setEditingEvent] = useState<EventItem | null>(null);

    const beginRequest = useRequestGuard();

    const load = useCallback(async () => {
        const { signal, isCurrent } = beginRequest();
        setLoading(true);
        setError(null);
        try {
            // Both endpoints use the same page size and ordering, so the report's
            // per-event stats line up 1:1 with the rendered page of events.
            const [listRes, reportRes] = await Promise.all([
                api.get<Paginated<EventItem>>('/api/v1/events', { params: { per_page: PAGE_SIZE }, signal }),
                api.get<EventReport>('/api/v1/reports/events', { params: { per_page: PAGE_SIZE }, signal }),
            ]);
            if (!isCurrent()) return;
            setEvents(listRes.data.data);
            setReport(reportRes.data);
        } catch (err) {
            if (signal.aborted || !isCurrent()) return;
            setError(apiErrorMessage(err));
        } finally {
            if (isCurrent()) setLoading(false);
        }
    }, [beginRequest]);

    useEffect(() => {
        void load();
    }, [load]);

    const statsById = new Map((report?.events ?? []).map((e) => [e.id, e]));

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

    const handleSaved = async () => {
        const wasEditing = editingEvent !== null;
        setModalOpen(false);
        setEditingEvent(null);
        flash(wasEditing ? 'Event updated.' : 'Event created.');
        await load();
    };

    const duplicate = async (event: EventItem) => {
        try {
            await api.post(`/api/v1/events/${event.id}/duplicate`);
            flash('Event duplicated.');
            await load();
        } catch (err) {
            setError(apiErrorMessage(err));
        }
    };

    const destroy = async (event: EventItem) => {
        if (!window.confirm(`Delete "${event.title}"? This cannot be undone.`)) return;
        try {
            await api.delete(`/api/v1/events/${event.id}`);
            flash('Event deleted.');
            await load();
        } catch (err) {
            setError(apiErrorMessage(err));
        }
    };

    return (
        <div className="stack">
            <header className="page-head">
                <div>
                    <h1>Your events</h1>
                    <p className="muted page-sub">
                        Manage your invitations, guests, and RSVPs in one place.
                    </p>
                </div>
                <button type="button" className="btn btn-primary" onClick={openCreate}>
                    Create event
                </button>
            </header>

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error ? <Alert kind="error">{error}</Alert> : null}

            {loading ? (
                <div className="card">
                    <LoadingBlock label="Loading events…" />
                </div>
            ) : events.length === 0 ? (
                <div className="card empty-state">
                    <h2>No events yet</h2>
                    <p className="muted">
                        Create your first event to start designing invitations and collecting RSVPs.
                    </p>
                    <button type="button" className="btn btn-primary" onClick={openCreate}>
                        Create event
                    </button>
                </div>
            ) : (
                <div className="event-grid">
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
