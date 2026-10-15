import type { Design, EventItem } from '../lib/types';

export const FONT_STACKS: Record<string, string> = {
    serif: '"Playfair Display", Georgia, "Times New Roman", serif',
    sans: 'Figtree, system-ui, -apple-system, "Segoe UI", sans-serif',
    script: '"Brush Script MT", "Segoe Script", cursive',
    modern: 'Montserrat, "Segoe UI", sans-serif',
};

function formatEventDate(event: EventItem): string {
    if (!event.event_start) {
        return '';
    }
    try {
        return new Intl.DateTimeFormat('en-GB', {
            dateStyle: 'full',
            timeStyle: 'short',
            timeZone: event.timezone || undefined,
        }).format(new Date(event.event_start));
    } catch {
        return event.event_start;
    }
}

export function TemplatePreview({
    event,
    design,
    guestName,
}: {
    event: EventItem;
    design: Design;
    guestName?: string;
}) {
    const colors = design.colors || {};
    const background = colors.background || '#ffffff';
    const surface = colors.surface || '#f8fafc';
    const primary = colors.primary || '#111827';
    const accent = colors.accent || '#b45309';
    const text = colors.text || '#111827';
    const fontFamily = FONT_STACKS[design.font] || FONT_STACKS.serif;

    return (
        <div
            style={{
                background,
                color: text,
                fontFamily,
                borderRadius: 14,
                border: `1px solid ${surface}`,
                overflow: 'hidden',
                boxShadow: '0 10px 30px rgba(15,23,42,0.08)',
            }}
        >
            {design.background_image ? (
                <div
                    style={{
                        height: 180,
                        backgroundImage: `url(${design.background_image})`,
                        backgroundSize: 'cover',
                        backgroundPosition: 'center',
                    }}
                />
            ) : null}

            <div style={{ padding: '2.5rem 2rem', textAlign: 'center' }}>
                <div
                    style={{
                        letterSpacing: '0.18em',
                        textTransform: 'uppercase',
                        fontSize: 12,
                        color: accent,
                        fontWeight: 600,
                    }}
                >
                    {design.heading || 'You are invited'}
                </div>

                <h1 style={{ fontSize: 36, margin: '0.6rem 0 0.4rem', color: primary }}>
                    {event.title || 'Your event'}
                </h1>

                {design.hosts ? (
                    <div style={{ fontSize: 16, color: text }}>{design.hosts}</div>
                ) : null}

                <div
                    style={{
                        width: 64,
                        height: 2,
                        background: accent,
                        margin: '1rem auto',
                    }}
                />

                {guestName ? (
                    <p style={{ fontStyle: 'italic', marginBottom: '0.75rem' }}>Dear {guestName},</p>
                ) : null}

                {design.message ? (
                    <p style={{ whiteSpace: 'pre-wrap', margin: '0 auto', maxWidth: 520 }}>
                        {design.message}
                    </p>
                ) : null}

                <div style={{ marginTop: '1.25rem', fontWeight: 600 }}>{formatEventDate(event)}</div>
                {event.venue ? <div style={{ color: accent, marginTop: 4 }}>{event.venue}</div> : null}
                {event.description ? (
                    <p style={{ marginTop: '0.75rem', whiteSpace: 'pre-wrap' }}>{event.description}</p>
                ) : null}

                {design.rsvp_note ? (
                    <p style={{ marginTop: '1.5rem', fontSize: 13, opacity: 0.8 }}>{design.rsvp_note}</p>
                ) : null}
            </div>
        </div>
    );
}
