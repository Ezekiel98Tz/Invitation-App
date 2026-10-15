import { Link } from 'react-router-dom';
import type { EventItem, EventSummary } from '../lib/types';
import { ActionMenu } from './ActionMenu';

function formatDate(iso: string | null | undefined): string {
    if (!iso) return 'Date not set';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return new Intl.DateTimeFormat('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(d);
}

interface Props {
    event: EventItem;
    summary?: EventSummary;
    onEdit: () => void;
    onDesign: () => void;
    onDuplicate: () => void;
    onDelete: () => void;
    busy?: boolean;
}

export function EventCard({ event, summary, onEdit, onDesign, onDuplicate, onDelete, busy }: Props) {
    const total = summary?.guests_total ?? 0;
    const accepted = summary?.guests_accepted ?? 0;
    const responded = summary?.guests_responded ?? 0;
    const rate = summary?.response_rate ?? 0;
    const acceptedPct = total === 0 ? 0 : Math.round((accepted / total) * 100);

    return (
        <article className="event-card">
            <div className="event-card-head">
                <h3 className="event-title">
                    <Link to={`/events/${event.id}`}>{event.title}</Link>
                </h3>
                <ActionMenu
                    items={[
                        { label: 'Invitation design', onSelect: onDesign },
                        { label: 'Edit event', onSelect: onEdit },
                        { label: 'Duplicate', onSelect: onDuplicate },
                        { label: 'Delete event', onSelect: onDelete, danger: true },
                    ]}
                />
            </div>

            {event.celebration_id ? (
                <Link
                    to={`/celebrations/${event.celebration_id}`}
                    className="muted small"
                    style={{ textDecoration: 'none', alignSelf: 'flex-start' }}
                >
                    Celebration: {event.celebration?.name ?? 'View celebration'}
                </Link>
            ) : null}

            <dl className="event-meta">
                <div className="event-meta-row">
                    <dt>Date</dt>
                    <dd>{formatDate(event.event_start)}</dd>
                </div>
                <div className="event-meta-row">
                    <dt>Venue</dt>
                    <dd>{event.venue || '—'}</dd>
                </div>
            </dl>

            <div className="event-progress">
                {total === 0 ? (
                    <p className="muted small" style={{ margin: 0 }}>
                        No guests imported yet.
                    </p>
                ) : (
                    <>
                        <div className="event-progress-top">
                            <span>
                                <strong>{accepted}</strong> accepted
                            </span>
                            <span className="muted">{total} invited</span>
                        </div>
                        <div
                            className="progress"
                            role="progressbar"
                            aria-valuenow={acceptedPct}
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-label="Accepted guests as a share of invited guests"
                        >
                            <div className="progress-bar" style={{ width: `${acceptedPct}%` }} />
                        </div>
                        <div className="muted small">
                            {responded} responded · {rate}%
                        </div>
                    </>
                )}
            </div>

            <div className="event-card-foot">
                <Link className="btn btn-primary btn-sm" to={`/events/${event.id}`} aria-disabled={busy}>
                    Manage event
                </Link>
            </div>
        </article>
    );
}
