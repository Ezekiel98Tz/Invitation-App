import { Link } from 'react-router-dom';
import { useAuth } from '../lib/auth';
import { TemplatePreview } from '../components/TemplatePreview';
import type { Design, EventItem } from '../lib/types';

const sampleEvent: EventItem = {
    id: 0,
    title: 'Amara & Daniel',
    description: null,
    event_start: new Date(Date.now() + 1000 * 60 * 60 * 24 * 30).toISOString(),
    timezone: 'Africa/Dar_es_Salaam',
    venue: 'The Garden Pavilion',
    capacity: null,
};

const sampleDesign: Design = {
    template: 'blush-romance',
    heading: 'Together with their families',
    hosts: 'Amara & Daniel',
    message: 'Request the pleasure of your company as they celebrate their wedding.',
    rsvp_note: 'Kindly RSVP before the first of the month.',
    background_image: null,
    font: 'script',
    colors: {
        background: '#fff5f7',
        surface: '#ffe4ea',
        primary: '#9d174d',
        accent: '#db2777',
        text: '#4c0519',
    },
};

const features = [
    {
        title: 'Manage every event',
        body: 'Create events, track responses and keep all your celebrations in one place.',
    },
    {
        title: 'Guests & RSVPs',
        body: 'Import guest lists, send invitations, and collect RSVPs with custom questions.',
    },
    {
        title: 'Designed invitations',
        body: 'Pick a template, customise colours and fonts, and share a live preview instantly.',
    },
];

export function LandingPage() {
    const { user, loading } = useAuth();
    const authed = !loading && user !== null;

    return (
        <div className="app-shell">
            <header className="topbar">
                <span className="brand">Invitation Studio</span>
                <div className="nav">
                    {authed ? (
                        <Link className="btn btn-primary" to="/dashboard">
                            Go to dashboard
                        </Link>
                    ) : (
                        <>
                            <Link className="btn btn-ghost" to="/login">
                                Sign in
                            </Link>
                            <Link className="btn btn-primary" to="/register">
                                Create account
                            </Link>
                        </>
                    )}
                </div>
            </header>

            <main className="container">
                <section className="hero">
                    <div className="hero-grid">
                        <div>
                            <div className="eyebrow">Digital invitations</div>
                            <h1>Beautiful digital invitations, guests and RSVPs in one place.</h1>
                            <p className="muted" style={{ fontSize: '1.05rem', maxWidth: 520 }}>
                                Design an invitation, import your guest list, send it out, and watch the
                                RSVPs come in — all from a single dashboard.
                            </p>
                            <div className="row" style={{ marginTop: '1.5rem' }}>
                                {authed ? (
                                    <Link className="btn btn-primary" to="/dashboard">
                                        Go to dashboard
                                    </Link>
                                ) : (
                                    <>
                                        <Link className="btn btn-primary" to="/register">
                                            Get started — it&apos;s free
                                        </Link>
                                        <Link className="btn btn-ghost" to="/login">
                                            I already have an account
                                        </Link>
                                    </>
                                )}
                            </div>
                        </div>

                        <div>
                            <TemplatePreview event={sampleEvent} design={sampleDesign} guestName="Guest" />
                        </div>
                    </div>
                </section>

                <section className="features">
                    {features.map((f) => (
                        <div className="card" key={f.title}>
                            <h3>{f.title}</h3>
                            <p className="muted" style={{ margin: 0 }}>
                                {f.body}
                            </p>
                        </div>
                    ))}
                </section>
            </main>

            <footer className="container muted" style={{ paddingBottom: '2rem', fontSize: '0.85rem' }}>
                Invitation Studio — invitations, guests and RSVPs.
            </footer>
        </div>
    );
}
