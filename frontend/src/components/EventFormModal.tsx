import { useEffect, useState } from 'react';
import { api, apiErrorMessage, validationErrors } from '../lib/api';
import type { EventItem, EventTypeTaxonomy } from '../lib/types';
import { Field } from './ui';
import { Modal } from './Modal';

interface EventForm {
    id: number | null;
    title: string;
    description: string;
    event_start: string;
    timezone: string;
    venue: string;
    capacity: string;
    event_type: string;
    allow_plus_ones: boolean;
    enable_waitlist: boolean;
    capabilities: Record<string, boolean>;
}

const emptyForm: EventForm = {
    id: null,
    title: '',
    description: '',
    event_start: '',
    timezone: 'Africa/Dar_es_Salaam',
    venue: '',
    capacity: '',
    event_type: '',
    allow_plus_ones: false,
    enable_waitlist: false,
    capabilities: {},
};

/**
 * Capabilities that already have a dedicated control in this form or whose
 * backing data is edited elsewhere, so they are not duplicated here.
 */
const CAPABILITY_WITH_DEDICATED_CONTROL = new Set(['plus_ones', 'custom_questions']);

function toDatetimeLocal(value: string | null | undefined): string {
    if (!value) return '';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function fromEvent(event: EventItem): EventForm {
    return {
        id: event.id,
        title: event.title,
        description: event.description ?? '',
        event_start: toDatetimeLocal(event.event_start),
        timezone: event.timezone || 'Africa/Dar_es_Salaam',
        venue: event.venue,
        capacity: event.capacity ? String(event.capacity) : '',
        event_type: event.event_type ?? '',
        allow_plus_ones: Boolean(event.settings?.allow_plus_ones),
        enable_waitlist: Boolean(event.settings?.enable_waitlist),
        capabilities: { ...(event.capabilities ?? event.settings?.capabilities ?? {}) },
    };
}

interface Props {
    open: boolean;
    /** When set, the modal edits this event; otherwise it creates a new one. */
    event?: EventItem | null;
    /** When set (and creating), the new event is associated with this celebration. */
    celebrationId?: number | null;
    celebrationName?: string;
    onClose: () => void;
    onSaved: (event: EventItem) => void;
    onError: (message: string) => void;
}

/**
 * Shared create/edit event form. Used by the dashboard (standalone) and by a
 * celebration detail page (creation with a celebration context) so event
 * creation logic lives in exactly one place.
 */
export function EventFormModal({
    open,
    event = null,
    celebrationId = null,
    celebrationName,
    onClose,
    onSaved,
    onError,
}: Props) {
    const [form, setForm] = useState<EventForm>(emptyForm);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const [eventTypes, setEventTypes] = useState<EventTypeTaxonomy | null>(null);

    // Reset the form each time the modal opens for a given event.
    useEffect(() => {
        if (!open) return;
        setForm(event ? fromEvent(event) : emptyForm);
        setErrors({});
    }, [open, event]);

    // Load the event-type taxonomy lazily; it is optional presentation metadata.
    useEffect(() => {
        if (!open || eventTypes) return;
        let cancelled = false;
        (async () => {
            try {
                const { data } = await api.get<EventTypeTaxonomy>('/api/v1/event-types');
                if (!cancelled) setEventTypes(data);
            } catch {
                // Non-fatal: the select simply falls back to "Not specified".
            }
        })();
        return () => {
            cancelled = true;
        };
    }, [open, eventTypes]);

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setSaving(true);
        setErrors({});

        const settings: Record<string, unknown> = {
            allow_plus_ones: form.allow_plus_ones,
            custom_questions: [],
            enable_waitlist: form.enable_waitlist,
            event_type: form.event_type || null,
        };

        // Only send an explicit capability map when the taxonomy (the single
        // source of truth for which capabilities exist and are implemented) has
        // loaded. Sending it preserves the backend's deep-merge behaviour and
        // lets an operator opt a capability in or out per event.
        if (eventTypes) {
            const capabilities: Record<string, boolean> = { plus_ones: form.allow_plus_ones };
            for (const [key] of editorCapabilities) {
                capabilities[key] = form.capabilities[key] ?? false;
            }
            settings.capabilities = capabilities;
        }

        const payload: Record<string, unknown> = {
            title: form.title,
            description: form.description || null,
            event_start: form.event_start ? new Date(form.event_start).toISOString() : '',
            timezone: form.timezone,
            venue: form.venue,
            capacity: form.capacity ? Number(form.capacity) : null,
            settings,
        };

        // Only associate on creation; editing never changes membership.
        if (form.id === null && celebrationId) {
            payload.celebration_id = celebrationId;
        }

        try {
            const { data } =
                form.id === null
                    ? await api.post<EventItem>('/api/v1/events', payload)
                    : await api.put<EventItem>(`/api/v1/events/${form.id}`, payload);
            onSaved(data);
        } catch (err) {
            const fieldErrors = validationErrors(err);
            setErrors(fieldErrors);
            if (!Object.keys(fieldErrors).length) {
                onError(apiErrorMessage(err));
            }
        } finally {
            setSaving(false);
        }
    };

    const isCreate = form.id === null;

    const editorCapabilities = eventTypes
        ? Object.entries(eventTypes.capabilities).filter(
              ([key, definition]) =>
                  definition.implemented && !CAPABILITY_WITH_DEDICATED_CONTROL.has(key),
          )
        : [];

    return (
        <Modal open={open} title={isCreate ? 'Create event' : 'Edit event'} onClose={onClose} busy={saving}>
            <form onSubmit={submit}>
                <Field label="Title" error={errors.title?.[0]}>
                    <input
                        className="input"
                        value={form.title}
                        onChange={(e) => setForm({ ...form, title: e.target.value })}
                        required
                    />
                </Field>
                <Field label="Venue" error={errors.venue?.[0]}>
                    <input
                        className="input"
                        value={form.venue}
                        onChange={(e) => setForm({ ...form, venue: e.target.value })}
                        required
                    />
                </Field>
                <Field label="Event type" error={errors['settings.event_type']?.[0]}>
                    <select
                        className="select"
                        value={form.event_type}
                        onChange={(e) => setForm({ ...form, event_type: e.target.value })}
                    >
                        <option value="">Not specified</option>
                        {Object.entries(eventTypes?.types ?? {}).map(([key, type]) => (
                            <option key={key} value={key}>
                                {type.label}
                            </option>
                        ))}
                    </select>
                </Field>
                <div className="grid-2">
                    <Field label="Event start" error={errors.event_start?.[0]}>
                        <input
                            className="input"
                            type="datetime-local"
                            value={form.event_start}
                            onChange={(e) => setForm({ ...form, event_start: e.target.value })}
                            required
                        />
                    </Field>
                    <Field label="Timezone" error={errors.timezone?.[0]}>
                        <input
                            className="input"
                            value={form.timezone}
                            onChange={(e) => setForm({ ...form, timezone: e.target.value })}
                        />
                    </Field>
                </div>
                <Field label="Capacity (optional)" error={errors.capacity?.[0]}>
                    <input
                        className="input"
                        type="number"
                        min={1}
                        value={form.capacity}
                        onChange={(e) => setForm({ ...form, capacity: e.target.value })}
                    />
                </Field>
                <Field label="Description">
                    <textarea
                        className="textarea"
                        rows={3}
                        value={form.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })}
                    />
                </Field>
                <label className="row" style={{ marginBottom: '0.85rem' }}>
                    <input
                        type="checkbox"
                        checked={form.allow_plus_ones}
                        onChange={(e) => setForm({ ...form, allow_plus_ones: e.target.checked })}
                    />
                    <span>Allow plus-ones</span>
                </label>

                {editorCapabilities.length > 0 ? (
                    <fieldset className="capability-fieldset">
                        <legend className="label">Event capabilities</legend>
                        <p className="form-help" style={{ marginTop: 0 }}>
                            Optional modules, off by default. They are a per-event choice — selecting an event type
                            does not turn them on.
                        </p>
                        {editorCapabilities.map(([key, definition]) => (
                            <label key={key} className="capability-option">
                                <input
                                    type="checkbox"
                                    checked={form.capabilities[key] ?? false}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            capabilities: { ...form.capabilities, [key]: e.target.checked },
                                        })
                                    }
                                />
                                <span>
                                    <span className="cell-strong">{definition.label}</span>
                                    {definition.description ? (
                                        <span className="muted small"> — {definition.description}</span>
                                    ) : null}
                                </span>
                            </label>
                        ))}
                    </fieldset>
                ) : null}

                {isCreate && celebrationId && celebrationName ? (
                    <p className="form-help">
                        This event will be added to the celebration “{celebrationName}”. It keeps its own date,
                        venue, guests, invitation design, and RSVPs.
                    </p>
                ) : null}

                <div className="modal-footer">
                    <button type="button" className="btn btn-ghost" onClick={onClose} disabled={saving}>
                        Cancel
                    </button>
                    <button type="submit" className="btn btn-primary" disabled={saving}>
                        {saving ? 'Saving…' : 'Save'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
