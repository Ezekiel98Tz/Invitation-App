import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, apiErrorMessage } from '../lib/api';
import type { PublicPass, PublicPassState } from '../lib/types';
import { Alert, LoadingBlock } from '../components/ui';

interface PassCopy {
    title: string;
    description: string;
}

const STATE_COPY: Record<PublicPassState, PassCopy> = {
    valid: {
        title: 'Ready for entry',
        description: 'Present this pass at the entrance. Staff will scan it and confirm your arrival.',
    },
    pending: {
        title: 'RSVP required',
        description: 'Respond to your invitation before an event pass can be issued.',
    },
    declined: {
        title: 'Pass unavailable',
        description: 'Your response is recorded as not attending. Update your RSVP before the event if your plans change.',
    },
    not_active: {
        title: 'Pass not active yet',
        description: 'Your entry credential is not active. Contact the event organizer if you believe this is a mistake.',
    },
    not_yet: {
        title: 'Entry is not open yet',
        description: 'This pass becomes valid closer to the event. Return during the entry window.',
    },
    expired: {
        title: 'Pass expired',
        description: 'The entry window for this event has closed.',
    },
    revoked: {
        title: 'Pass revoked',
        description: 'This pass can no longer be used. Contact the event organizer for help.',
    },
    disabled: {
        title: 'Event pass unavailable',
        description: 'Entry passes are not enabled for this event.',
    },
    unavailable: {
        title: 'Event unavailable',
        description: 'This event is no longer available.',
    },
};

function formatEventDate(iso: string | null, timezone: string): string {
    if (!iso) return 'Date not set';

    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return iso;

    try {
        return new Intl.DateTimeFormat('en-GB', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            timeZone: timezone || undefined,
        }).format(date);
    } catch {
        return date.toLocaleString();
    }
}

function formatWindow(iso: string | null, timezone: string): string | null {
    if (!iso) return null;
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return null;

    try {
        return new Intl.DateTimeFormat('en-GB', {
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
            timeZone: timezone || undefined,
        }).format(date);
    } catch {
        return date.toLocaleString();
    }
}

export function PublicPassPage() {
    const { token } = useParams<{ token: string }>();
    const [pass, setPass] = useState<PublicPass | null>(null);
    const [loading, setLoading] = useState(true);
    const [notFound, setNotFound] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [qrFailed, setQrFailed] = useState(false);

    useEffect(() => {
        if (!token) return;

        const controller = new AbortController();
        setLoading(true);
        setNotFound(false);
        setError(null);
        setQrFailed(false);

        void api
            .get<PublicPass>(`/api/v1/public/invites/${token}/pass`, { signal: controller.signal })
            .then(({ data }) => {
                if (!controller.signal.aborted) setPass(data);
            })
            .catch((err) => {
                if (controller.signal.aborted) return;

                const status = (err as { response?: { status?: number } })?.response?.status;
                if (status === 404) setNotFound(true);
                else setError(apiErrorMessage(err));
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });

        return () => controller.abort();
    }, [token]);

    if (loading) {
        return (
            <div className="public-pass-page">
                <LoadingBlock label="Loading your event pass…" />
            </div>
        );
    }

    if (notFound) {
        return (
            <div className="public-pass-page">
                <section className="public-pass-state">
                    <p className="public-pass-kicker">Invitation Studio</p>
                    <h1>Event pass not found</h1>
                    <p className="muted">Check that you opened the complete link from your invitation.</p>
                </section>
            </div>
        );
    }

    if (!pass) {
        return (
            <div className="public-pass-page">
                <Alert kind="error">{error ?? 'Unable to load the event pass.'}</Alert>
            </div>
        );
    }

    const copy = STATE_COPY[pass.pass.state] ?? STATE_COPY.unavailable;
    const event = pass.event;
    const valid = pass.pass.state === 'valid' && pass.pass.qr_available;
    const qrUrl = token ? `/api/v1/public/invites/${token}/pass/qr` : null;
    const validFrom = event ? formatWindow(pass.pass.valid_from, event.timezone) : null;
    const validUntil = event ? formatWindow(pass.pass.valid_until, event.timezone) : null;

    return (
        <div className="public-pass-page">
            <main className={`public-pass${valid ? ' is-valid' : ' is-unavailable'}`}>
                <header className="public-pass-header">
                    <p className="public-pass-kicker">Event pass</p>
                    <h1>{event?.title ?? 'Your event'}</h1>
                    <p className="public-pass-guest">For {pass.guest.name}</p>
                </header>

                {event ? (
                    <dl className="public-pass-event">
                        <div>
                            <dt>When</dt>
                            <dd>{formatEventDate(event.event_start, event.timezone)}</dd>
                        </div>
                        <div>
                            <dt>Venue</dt>
                            <dd>{event.venue || 'Venue not set'}</dd>
                        </div>
                    </dl>
                ) : null}

                <section className={`public-pass-status state-${pass.pass.state}`} aria-labelledby="pass-status-title" role="status">
                    <h2 id="pass-status-title">{copy.title}</h2>
                    <p>{copy.description}</p>
                    {pass.pass.state === 'not_yet' && validFrom ? (
                        <p className="public-pass-window">Entry opens {validFrom}.</p>
                    ) : null}
                    {pass.pass.state === 'expired' && validUntil ? (
                        <p className="public-pass-window">Entry closed {validUntil}.</p>
                    ) : null}
                </section>

                {valid ? (
                    <section className="public-pass-qr" aria-labelledby="pass-qr-title">
                        <h2 id="pass-qr-title">Show this at the entrance</h2>
                        <div className="public-pass-qr-frame">
                            {qrFailed ? (
                                <Alert kind="error">The QR code could not be displayed. Use your separate entry code.</Alert>
                            ) : (
                                <img
                                    src={qrUrl ?? ''}
                                    alt={`Entry QR code for ${pass.guest.name}`}
                                    width={480}
                                    height={480}
                                    onError={() => setQrFailed(true)}
                                />
                            )}
                        </div>
                        <p className="public-pass-hint">
                            Staff will scan this code, check its validity, and explicitly confirm your arrival.
                            Showing the pass does not check you in.
                        </p>
                        <p className="public-pass-code-note">{pass.pass.entry_code_note}</p>
                        {validUntil ? <p className="public-pass-window">Valid until {validUntil}.</p> : null}
                    </section>
                ) : null}

                <footer className="public-pass-footer">
                    <Link to={`/e/${token}`} className="btn btn-ghost">
                        View invitation
                    </Link>
                    <span className="muted small">Invitation Studio</span>
                </footer>
            </main>
        </div>
    );
}
