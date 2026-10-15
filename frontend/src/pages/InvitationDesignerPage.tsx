import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, apiErrorMessage, validationErrors } from '../lib/api';
import type { Design, InvitationDesignResponse, TemplateDefinition } from '../lib/types';
import { Alert, Field, LoadingBlock } from '../components/ui';
import { TemplatePreview } from '../components/TemplatePreview';

export function InvitationDesignerPage() {
    const { id } = useParams<{ id: string }>();
    const eventId = id ?? '';
    const [data, setData] = useState<InvitationDesignResponse | null>(null);
    const [design, setDesign] = useState<Design | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [toast, setToast] = useState<string | null>(null);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const { data } = await api.get<InvitationDesignResponse>(`/api/v1/events/${eventId}/invitation`);
            setData(data);
            setDesign(data.design);
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setLoading(false);
        }
    }, [eventId]);

    useEffect(() => {
        void load();
    }, [load]);

    const templates = useMemo(() => data?.templates ?? {}, [data]);
    const fonts = data?.fonts ?? {};

    const applyTemplate = (key: string) => {
        if (!design) return;
        const template: TemplateDefinition | undefined = templates[key];
        setDesign({
            ...design,
            template: key,
            font: template?.font ?? design.font,
            colors: { ...(template?.colors ?? {}) },
        });
    };

    const updateColor = (name: string, value: string) => {
        if (!design) return;
        setDesign({ ...design, colors: { ...design.colors, [name]: value } });
    };

    const save = async () => {
        if (!design) return;
        setSaving(true);
        setErrors({});
        setError(null);
        try {
            const { data } = await api.put<{ design: Design }>(`/api/v1/events/${eventId}/invitation`, design);
            setDesign(data.design);
            setToast('Design saved.');
            setTimeout(() => setToast(null), 2500);
        } catch (err) {
            setErrors(validationErrors(err));
            setError(apiErrorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    if (loading) return <LoadingBlock label="Loading invitation design…" />;
    if (!data || !design) return <Alert kind="error">{error ?? 'Unable to load design.'}</Alert>;

    const colorFields: (keyof Design['colors'])[] = ['background', 'surface', 'primary', 'accent', 'text'];

    return (
        <div className="stack">
            <div className="row between">
                <div>
                    <Link to={`/events/${eventId}`} className="muted">
                        ← Back to event
                    </Link>
                    <h1 style={{ marginTop: '0.5rem' }}>Invitation design</h1>
                </div>
                <button className="btn btn-primary" onClick={save} disabled={saving}>
                    {saving ? 'Saving…' : 'Save design'}
                </button>
            </div>

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error ? <Alert kind="error">{error}</Alert> : null}

            <div className="grid-2">
                <div className="stack">
                    <div className="card">
                        <h3>Template</h3>
                        <div className="grid-2">
                            {Object.entries(templates).map(([key, template]) => (
                                <button
                                    key={key}
                                    type="button"
                                    className="btn btn-ghost"
                                    style={{
                                        flexDirection: 'column',
                                        alignItems: 'stretch',
                                        textAlign: 'left',
                                        borderColor: design.template === key ? 'var(--primary)' : undefined,
                                        borderWidth: design.template === key ? 2 : 1,
                                    }}
                                    onClick={() => applyTemplate(key)}
                                >
                                    <span
                                        style={{
                                            display: 'block',
                                            height: 36,
                                            borderRadius: 6,
                                            marginBottom: 8,
                                            background: template.colors.background,
                                            border: `3px solid ${template.colors.accent}`,
                                        }}
                                    />
                                    <strong>{template.name}</strong>
                                    <span className="muted" style={{ fontSize: '0.75rem' }}>
                                        {template.occasion}
                                    </span>
                                </button>
                            ))}
                        </div>
                        {errors.template?.[0] ? <div className="error-text">{errors.template[0]}</div> : null}
                    </div>

                    <div className="card">
                        <h3>Content</h3>
                        <Field label="Heading" error={errors.heading?.[0]}>
                            <input
                                className="input"
                                value={design.heading}
                                onChange={(e) => setDesign({ ...design, heading: e.target.value })}
                            />
                        </Field>
                        <Field label="Hosts" error={errors.hosts?.[0]}>
                            <input
                                className="input"
                                value={design.hosts}
                                onChange={(e) => setDesign({ ...design, hosts: e.target.value })}
                            />
                        </Field>
                        <Field label="Message" error={errors.message?.[0]}>
                            <textarea
                                className="textarea"
                                rows={4}
                                value={design.message}
                                onChange={(e) => setDesign({ ...design, message: e.target.value })}
                            />
                        </Field>
                        <Field label="RSVP note" error={errors.rsvp_note?.[0]}>
                            <input
                                className="input"
                                value={design.rsvp_note}
                                onChange={(e) => setDesign({ ...design, rsvp_note: e.target.value })}
                            />
                        </Field>
                    </div>

                    <div className="card">
                        <h3>Theme</h3>
                        <Field label="Font">
                            <select
                                className="select"
                                value={design.font}
                                onChange={(e) => setDesign({ ...design, font: e.target.value })}
                            >
                                {Object.entries(fonts).map(([key, stack]) => (
                                    <option key={key} value={key}>
                                        {key}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <div className="grid-2">
                            {colorFields.map((name) => (
                                <Field key={name} label={name.charAt(0).toUpperCase() + name.slice(1)}>
                                    <div className="row">
                                        <input
                                            type="color"
                                            value={design.colors[name] ?? '#ffffff'}
                                            onChange={(e) => updateColor(name as string, e.target.value)}
                                        />
                                        <input
                                            className="input"
                                            value={design.colors[name] ?? ''}
                                            onChange={(e) => updateColor(name as string, e.target.value)}
                                        />
                                    </div>
                                </Field>
                            ))}
                        </div>
                        <Field label="Background image URL (optional)" error={errors.background_image?.[0]}>
                            <input
                                className="input"
                                value={design.background_image ?? ''}
                                onChange={(e) => setDesign({ ...design, background_image: e.target.value || null })}
                            />
                        </Field>
                    </div>
                </div>

                <div className="stack">
                    <div className="card">
                        <h3>Live preview</h3>
                        <p className="muted" style={{ marginTop: 0 }}>
                            Updates as you edit.
                        </p>
                        <TemplatePreview event={data.event} design={design} />
                    </div>
                </div>
            </div>
        </div>
    );
}
