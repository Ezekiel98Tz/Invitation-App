import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, apiErrorMessage, validationErrors } from '../lib/api';
import type { Celebration, Paginated } from '../lib/types';
import { Alert, EmptyState, Field, LoadingBlock, PageHeading } from '../components/ui';
import { Modal } from '../components/Modal';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { ActionMenu } from '../components/ActionMenu';
import { useRequestGuard } from '../lib/useRequestGuard';

interface CelebrationForm {
    id: number | null;
    name: string;
    description: string;
}

const emptyForm: CelebrationForm = { id: null, name: '', description: '' };

export function CelebrationsPage() {
    const [celebrations, setCelebrations] = useState<Celebration[]>([]);
    const [pagination, setPagination] = useState<Paginated<Celebration> | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [toast, setToast] = useState<string | null>(null);

    const [modalOpen, setModalOpen] = useState(false);
    const [form, setForm] = useState<CelebrationForm>(emptyForm);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);

    const [deleteTarget, setDeleteTarget] = useState<Celebration | null>(null);
    const [deleting, setDeleting] = useState(false);

    const flash = (message: string) => {
        setToast(message);
        window.setTimeout(() => setToast(null), 3500);
    };

    const beginLoad = useRequestGuard();

    const load = useCallback(async (page = 1) => {
        const { signal, isCurrent } = beginLoad();
        setLoading(true);
        setError(null);
        try {
            const { data } = await api.get<Paginated<Celebration>>('/api/v1/celebrations', {
                params: { page },
                signal,
            });
            if (!isCurrent()) return;
            setCelebrations(data.data);
            setPagination(data);
        } catch (err) {
            if (signal.aborted || !isCurrent()) return;
            setError(apiErrorMessage(err));
        } finally {
            if (isCurrent()) setLoading(false);
        }
    }, [beginLoad]);

    useEffect(() => {
        void load(1);
    }, [load]);

    const openCreate = () => {
        setForm(emptyForm);
        setErrors({});
        setModalOpen(true);
    };

    const openRename = (celebration: Celebration) => {
        setForm({ id: celebration.id, name: celebration.name, description: celebration.description ?? '' });
        setErrors({});
        setModalOpen(true);
    };

    const save = async (e: React.FormEvent) => {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        const payload = { name: form.name, description: form.description.trim() === '' ? null : form.description };
        try {
            if (form.id === null) {
                await api.post('/api/v1/celebrations', payload);
                flash('Celebration created.');
            } else {
                await api.put(`/api/v1/celebrations/${form.id}`, payload);
                flash('Celebration updated.');
            }
            setModalOpen(false);
            await load(pagination?.current_page ?? 1);
        } catch (err) {
            setErrors(validationErrors(err));
            if (!Object.keys(validationErrors(err)).length) {
                setError(apiErrorMessage(err));
            }
        } finally {
            setSaving(false);
        }
    };

    const confirmDelete = async () => {
        if (!deleteTarget) return;
        setDeleting(true);
        try {
            const { data } = await api.delete<{ detached_events: number }>(
                `/api/v1/celebrations/${deleteTarget.id}`,
            );
            const name = deleteTarget.name;
            setDeleteTarget(null);
            flash(
                data.detached_events > 0
                    ? `"${name}" deleted. ${data.detached_events} event(s) kept as standalone.`
                    : `"${name}" deleted.`,
            );
            await load(pagination?.current_page ?? 1);
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setDeleting(false);
        }
    };

    return (
        <div className="stack celebrations-page">
            <PageHeading
                title="Celebrations"
                description="Group related occasions together. Each event keeps its own date, venue, guest list, invitation, and RSVP lifecycle."
                actions={
                    <button type="button" className="btn btn-primary" onClick={openCreate}>
                        New celebration
                    </button>
                }
            />

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error && (loading || celebrations.length > 0) ? <Alert kind="error">{error}</Alert> : null}

            {loading ? (
                <section className="list-loading" aria-label="Loading celebrations" aria-busy="true">
                    <LoadingBlock label="Loading celebrations…" />
                </section>
            ) : error && celebrations.length === 0 ? (
                <section className="page-state page-state-error" aria-label="Celebrations could not be loaded">
                    <Alert kind="error">{error}</Alert>
                    <button type="button" className="btn btn-ghost" onClick={() => void load(1)}>
                        Try again
                    </button>
                </section>
            ) : celebrations.length === 0 ? (
                <EmptyState
                    title="Group the occasions that belong together"
                    description="A celebration is a container for related events—not a shared event record. Dates, venues, guests, invitations, and RSVPs remain with each event."
                    action={
                        <button type="button" className="btn btn-primary" onClick={openCreate}>
                            Create your first celebration
                        </button>
                    }
                />
            ) : (
                <div className="celebration-list" aria-label="Celebrations">
                    {celebrations.map((celebration) => {
                        const count = celebration.events_count ?? 0;
                        return (
                            <article key={celebration.id} className="celebration-row">
                                <div className="celebration-row-copy">
                                    <h2 className="celebration-name">
                                        <Link to={`/celebrations/${celebration.id}`}>{celebration.name}</Link>
                                    </h2>
                                    <p className="muted celebration-description">
                                        {celebration.description || 'No description added.'}
                                    </p>
                                </div>
                                <div className="celebration-event-count" aria-label={`${count} events in this celebration`}>
                                    <strong>{count}</strong>
                                    <span>{count === 1 ? 'event' : 'events'}</span>
                                </div>
                                <div className="row celebration-row-actions">
                                    <Link className="btn btn-ghost btn-sm" to={`/celebrations/${celebration.id}`}>
                                        View events
                                    </Link>
                                    <ActionMenu
                                        label={`Actions for ${celebration.name}`}
                                        items={[
                                            { label: 'Rename', onSelect: () => openRename(celebration) },
                                            { label: 'Delete', onSelect: () => setDeleteTarget(celebration), danger: true },
                                        ]}
                                    />
                                </div>
                            </article>
                        );
                    })}
                </div>
            )}

            {pagination && pagination.last_page > 1 ? (
                <nav className="row between pagination" aria-label="Celebrations pagination">
                    <span className="muted">
                        Page {pagination.current_page} of {pagination.last_page} · {pagination.total ?? 0} celebration
                        {(pagination.total ?? 0) === 1 ? '' : 's'}
                    </span>
                    <div className="row">
                        <button
                            type="button"
                            className="btn btn-sm btn-ghost"
                            disabled={!pagination.prev_page_url || loading}
                            onClick={() => load(pagination.current_page - 1)}
                        >
                            Previous
                        </button>
                        <button
                            type="button"
                            className="btn btn-sm btn-ghost"
                            disabled={!pagination.next_page_url || loading}
                            onClick={() => load(pagination.current_page + 1)}
                        >
                            Next
                        </button>
                    </div>
                </nav>
            ) : null}

            <Modal
                open={modalOpen}
                title={form.id === null ? 'New celebration' : 'Rename celebration'}
                onClose={() => setModalOpen(false)}
                busy={saving}
                maxWidth={480}
            >
                <form onSubmit={save}>
                    <Field label="Name" error={errors.name?.[0]}>
                        <input
                            className="input"
                            value={form.name}
                            onChange={(e) => setForm({ ...form, name: e.target.value })}
                            maxLength={255}
                            required
                        />
                    </Field>
                    <Field label="Description (optional)" error={errors.description?.[0]}>
                        <textarea
                            className="textarea"
                            rows={3}
                            value={form.description}
                            onChange={(e) => setForm({ ...form, description: e.target.value })}
                            maxLength={2000}
                        />
                    </Field>
                    <p className="form-help">
                        A celebration groups occasions. It does not share guests, contributions, or designs between
                        them.
                    </p>
                    <div className="modal-footer">
                        <button
                            type="button"
                            className="btn btn-ghost"
                            onClick={() => setModalOpen(false)}
                            disabled={saving}
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className="btn btn-primary"
                            disabled={saving || form.name.trim() === ''}
                        >
                            {saving ? 'Saving…' : form.id === null ? 'Create celebration' : 'Save changes'}
                        </button>
                    </div>
                </form>
            </Modal>

            <ConfirmDialog
                open={deleteTarget !== null}
                title="Delete celebration"
                message={
                    <>
                        Delete <strong>{deleteTarget?.name}</strong>? Its events will be kept and become standalone —
                        no events, guests, or RSVPs are deleted.
                    </>
                }
                confirmLabel="Delete celebration"
                danger
                busy={deleting}
                onConfirm={confirmDelete}
                onCancel={() => setDeleteTarget(null)}
            />
        </div>
    );
}
