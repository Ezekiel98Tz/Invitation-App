import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api, apiErrorMessage, validationErrors } from '../lib/api';
import type { Contact, ContactGuest, ContactGuestPage } from '../lib/types';
import { Alert, Field, LoadingBlock, StatusBadge } from '../components/ui';
import { Modal } from '../components/Modal';
import { ConfirmDialog } from '../components/ConfirmDialog';

interface ContactForm {
    name: string;
    email: string;
    phone: string;
    notes: string;
}

function formFromContact(contact: Contact): ContactForm {
    return {
        name: contact.name,
        email: contact.email ?? '',
        phone: contact.phone ?? '',
        notes: contact.notes ?? '',
    };
}

function formatWhen(iso: string | null | undefined): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleString();
}

export function ContactDetailPage() {
    const { id } = useParams<{ id: string }>();
    const contactId = id ?? '';
    const navigate = useNavigate();

    const [contact, setContact] = useState<Contact | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [toast, setToast] = useState<string | null>(null);

    const [guests, setGuests] = useState<ContactGuest[]>([]);
    const [guestPage, setGuestPage] = useState<ContactGuestPage | null>(null);
    const [guestsLoading, setGuestsLoading] = useState(false);

    const [modalOpen, setModalOpen] = useState(false);
    const [form, setForm] = useState<ContactForm>({ name: '', email: '', phone: '', notes: '' });
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);

    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const flash = (message: string) => {
        setToast(message);
        window.setTimeout(() => setToast(null), 3500);
    };

    const loadContact = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const { data } = await api.get<Contact>(`/api/v1/contacts/${contactId}`);
            setContact(data);
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setLoading(false);
        }
    }, [contactId]);

    const loadGuests = useCallback(async (page = 1) => {
        setGuestsLoading(true);
        try {
            const { data } = await api.get<ContactGuestPage>(`/api/v1/contacts/${contactId}/guests`, {
                params: { page },
            });
            setGuests(data.data);
            setGuestPage(data);
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setGuestsLoading(false);
        }
    }, [contactId]);

    useEffect(() => {
        void loadContact();
        void loadGuests(1);
    }, [loadContact, loadGuests]);

    const openEdit = () => {
        if (!contact) return;
        setForm(formFromContact(contact));
        setErrors({});
        setModalOpen(true);
    };

    const save = async (e: React.FormEvent) => {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        const payload = {
            name: form.name,
            email: form.email.trim() === '' ? null : form.email,
            phone: form.phone.trim() === '' ? null : form.phone,
            notes: form.notes.trim() === '' ? null : form.notes,
        };
        try {
            const { data } = await api.patch<Contact>(`/api/v1/contacts/${contactId}`, payload);
            setContact(data);
            setModalOpen(false);
            flash('Contact updated.');
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
        setDeleting(true);
        try {
            await api.delete(`/api/v1/contacts/${contactId}`);
            navigate('/contacts', { replace: true });
        } catch (err) {
            setError(apiErrorMessage(err));
            setDeleteOpen(false);
        } finally {
            setDeleting(false);
        }
    };

    if (loading) return <LoadingBlock label="Loading contact…" />;
    if (!contact) return <Alert kind="error">{error ?? 'Unable to load this contact.'}</Alert>;

    return (
        <div className="stack">
            <div className="row between">
                <div>
                    <Link to="/contacts" className="muted">
                        ← Back to contacts
                    </Link>
                    <h1 style={{ marginTop: '0.5rem' }}>{contact.name}</h1>
                    <p className="muted" style={{ marginTop: 0 }}>
                        {contact.email || 'No email'}
                        {contact.phone ? ` · ${contact.phone}` : ''}
                    </p>
                </div>
                <div className="row">
                    <button type="button" className="btn btn-ghost" onClick={openEdit}>
                        Edit contact
                    </button>
                    <button type="button" className="btn btn-danger-solid" onClick={() => setDeleteOpen(true)}>
                        Delete
                    </button>
                </div>
            </div>

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error ? <Alert kind="error">{error}</Alert> : null}

            <div className="card">
                <h3 style={{ marginTop: 0 }}>Contact details</h3>
                <dl className="details-list">
                    <div>
                        <dt>Name</dt>
                        <dd>{contact.name}</dd>
                    </div>
                    <div>
                        <dt>Email</dt>
                        <dd>{contact.email || '—'}</dd>
                    </div>
                    <div>
                        <dt>Phone</dt>
                        <dd>{contact.phone || '—'}</dd>
                    </div>
                    <div>
                        <dt>Notes</dt>
                        <dd>{contact.notes || '—'}</dd>
                    </div>
                    <div>
                        <dt>Linked guests</dt>
                        <dd>{contact.guests_count}</dd>
                    </div>
                </dl>
            </div>

            <div className="card">
                <h3 style={{ marginTop: 0 }}>Linked guests</h3>
                {guestsLoading ? (
                    <LoadingBlock label="Loading linked guests…" />
                ) : guests.length === 0 ? (
                    <p className="muted" style={{ marginBottom: 0 }}>
                        No guest records are linked to this contact.
                    </p>
                ) : (
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Guest</th>
                                    <th className="col-hide-sm">Event</th>
                                    <th className="col-hide-sm">When</th>
                                    <th>RSVP</th>
                                </tr>
                            </thead>
                            <tbody>
                                {guests.map((guest) => (
                                    <tr key={guest.id}>
                                        <td className="cell-strong">
                                            {guest.event ? (
                                                <Link to={`/events/${guest.event.id}`}>{guest.name}</Link>
                                            ) : (
                                                guest.name
                                            )}
                                        </td>
                                        <td className="muted col-hide-sm">{guest.event?.title ?? '—'}</td>
                                        <td className="muted col-hide-sm">
                                            {formatWhen(guest.event?.event_start)}
                                        </td>
                                        <td>
                                            <StatusBadge status={guest.status} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {guestPage && guestPage.last_page > 1 ? (
                    <div className="row between pagination">
                        <span className="muted">
                            Page {guestPage.current_page} of {guestPage.last_page}
                        </span>
                        <div className="row">
                            <button
                                type="button"
                                className="btn btn-sm btn-ghost"
                                disabled={!guestPage.prev_page_url}
                                onClick={() => loadGuests(guestPage.current_page - 1)}
                            >
                                Previous
                            </button>
                            <button
                                type="button"
                                className="btn btn-sm btn-ghost"
                                disabled={!guestPage.next_page_url}
                                onClick={() => loadGuests(guestPage.current_page + 1)}
                            >
                                Next
                            </button>
                        </div>
                    </div>
                ) : null}
            </div>

            <Modal open={modalOpen} title="Edit contact" onClose={() => setModalOpen(false)} busy={saving} maxWidth={480}>
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
                    <Field label="Email (optional)" error={errors.email?.[0]}>
                        <input
                            className="input"
                            type="email"
                            value={form.email}
                            onChange={(e) => setForm({ ...form, email: e.target.value })}
                        />
                    </Field>
                    <Field label="Phone (optional)" error={errors.phone?.[0]}>
                        <input
                            className="input"
                            value={form.phone}
                            onChange={(e) => setForm({ ...form, phone: e.target.value })}
                        />
                    </Field>
                    <Field label="Notes (optional)" error={errors.notes?.[0]}>
                        <textarea
                            className="textarea"
                            rows={3}
                            value={form.notes}
                            onChange={(e) => setForm({ ...form, notes: e.target.value })}
                            maxLength={2000}
                        />
                    </Field>
                    <p className="form-help">
                        Editing a contact does not change any linked guest's name, email, or phone.
                    </p>
                    <div className="modal-footer">
                        <button type="button" className="btn btn-ghost" onClick={() => setModalOpen(false)} disabled={saving}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={saving || form.name.trim() === ''}>
                            {saving ? 'Saving…' : 'Save changes'}
                        </button>
                    </div>
                </form>
            </Modal>

            <ConfirmDialog
                open={deleteOpen}
                title="Delete contact"
                message={
                    <>
                        Delete <strong>{contact.name}</strong>?{' '}
                        {contact.guests_count > 0 ? (
                            <>
                                {contact.guests_count} linked guest record(s) will be <strong>kept</strong> but detached
                                from this contact. No guests, RSVPs, or deliveries are deleted.
                            </>
                        ) : (
                            <>No guest records are linked to this contact.</>
                        )}
                    </>
                }
                confirmLabel="Delete contact"
                danger
                busy={deleting}
                onConfirm={confirmDelete}
                onCancel={() => setDeleteOpen(false)}
            />
        </div>
    );
}
