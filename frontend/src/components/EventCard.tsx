import { Link } from 'react-router-dom';
import type { EventItem, EventSummary } from '../lib/types';
import { ActionMenu } from './ActionMenu';

interface EventDateParts {
    day: string;
    month: string;
    year: string;
    time: string;
}

function eventDateParts(event: EventItem): EventDateParts | null {
    if (!event.event_start) return null;
    const date = new Date(event.event_start);
    if (Number.isNaN(date.getTime())) return null;

    try {
        const options = { timeZone: event.timezone || undefined };
        return {
            day: new Intl.DateTimeFormat('en-GB', { ...options, day: '2-digit' }).format(date),
            month: new Intl.DateTimeFormat('en-GB', { ...options, month: 'short' }).format(date),
            year: new Intl.DateTimeFormat('en-GB', { ...options, year: 'numeric' }).format(date),
            time: new Intl.DateTimeFormat('en-GB', {
                ...options,
                hour: '2-digit',
                minute: '2-digit',
            }).format(date),
        };
    } catch {
        return null;
    }
}

function eventTypeLabel(eventType: string): string {
    return eventType
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

interface Props {
    event: EventItem;
    summary?: EventSummary;
    onEdit: () => void;
    onDesign: () => void;
    onDuplicate: () => void;
    onDelete: () => void;
}

export function EventCard({ event, summary, onEdit, onDesign, onDuplicate, onDelete }: Props) {
    const date = eventDateParts(event);
    const responseRate = Math.max(0, Math.min(100, Math.round(summary?.response_rate ?? 0)));

    return (
        <article className="event-card">
            <div
                className="event-date-block"
                role="group"
                aria-label={date ? `${date.month} ${date.day}, ${date.year}` : 'Date not set'}
            >
                {date ? (
                    <>
                        <span className="event-date-month">{date.month}</span>
                        <strong className="event-date-day">{date.day}</strong>
                        <span className="event-date-year">{date.year}</span>
                    </>
                ) : (
                    <span className="event-date-unset">Date<br />not set</span>
                )}
            </div>

            <div className="event-card-content">
                <div className="event-card-head">
                    <h2 className="event-title">
                        <Link to={`/events/${event.id}`}>{event.title}</Link>
                    </h2>
                    <ActionMenu
                        label={`Actions for ${event.title}`}
                        items={[
                            { label: 'Invitation design', onSelect: onDesign },
                            { label: 'Edit event', onSelect: onEdit },
                            { label: 'Duplicate', onSelect: onDuplicate },
                            { label: 'Delete event', onSelect: onDelete, danger: true },
                        ]}
                    />
                </div>

                <div className="event-tags">
                    {event.event_type ? <span className="event-type-label">{eventTypeLabel(event.event_type)}</span> : null}
                    {event.celebration_id ? (
                        <Link to={`/celebrations/${event.celebration_id}`} className="event-celebration-link">
                            {event.celebration?.name ?? 'View celebration'}
                        </Link>
                    ) : null}
                </div>

                <p className="event-location">
                    <span>{event.venue || 'Venue not set'}</span>
                    {date ? <span>{date.time} · {event.timezone || 'Local time'}</span> : null}
                </p>
            </div>

            <div className="event-response">
                {summary ? (
                    summary.guests_total > 0 ? (
                        <>
                            <div className="event-response-heading">
                                <strong>{summary.guests_responded} of {summary.guests_total}</strong>
                                <span>responded</span>
                            </div>
                            <div
                                className="progress event-response-progress"
                                role="progressbar"
                                aria-valuenow={responseRate}
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-label={`RSVP response rate for ${event.title}`}
                            >
                                <div className="progress-bar" style={{ width: `${responseRate}%` }} />
                            </div>
                            <p className="event-response-detail">
                                {summary.guests_accepted} accepted · {summary.guests_declined} declined · {responseRate}% response
                            </p>
                        </>
                    ) : (
                        <p className="event-response-empty">No guests added yet</p>
                    )
                ) : null}
            </div>

            <div className="event-card-action">
                <Link className="btn btn-ghost btn-sm" to={`/events/${event.id}`}>
                    Manage event
                </Link>
            </div>
        </article>
    );
}
