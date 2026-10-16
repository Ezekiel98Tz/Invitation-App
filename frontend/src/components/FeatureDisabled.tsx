import { Link } from 'react-router-dom';

interface Props {
    eventId: string;
    /** The human-readable feature name, e.g. "Contributions (pledges)". */
    feature: string;
    /** Optional heading override. */
    title?: string;
}

/**
 * Friendly state shown when a direct URL targets a feature that is disabled for
 * the event. Capabilities are opt-in per event, so this is a normal state — not
 * an error — and the copy explains how to change it.
 */
export function FeatureDisabled({ eventId, feature, title }: Props) {
    return (
        <div className="stack">
            <div>
                <Link to={`/events/${eventId}`} className="muted">
                    ← Back to event
                </Link>
            </div>
            <section className="card feature-disabled" role="status" aria-live="polite">
                <span className="feature-disabled-tag">Feature disabled</span>
                <h1 style={{ marginTop: '0.6rem' }}>
                    {title ?? `${feature} is disabled for this event`}
                </h1>
                <p className="muted" style={{ maxWidth: '62ch' }}>
                    {feature} is an opt-in capability and it is currently turned off for this event. Nothing is
                    broken and no data was lost. The event owner can turn it on from the event settings, and it
                    becomes available here immediately after.
                </p>
                <div className="row" style={{ marginTop: '1rem' }}>
                    <Link className="btn btn-primary" to={`/events/${eventId}`}>
                        Back to event
                    </Link>
                    <Link className="btn btn-ghost" to="/dashboard">
                        Go to dashboard
                    </Link>
                </div>
            </section>
        </div>
    );
}
