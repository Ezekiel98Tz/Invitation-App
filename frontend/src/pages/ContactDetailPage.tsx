import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api, apiErrorMessage, validationErrors } from '../lib/api';
import type { Contact, ContactGuest, ContactGuestPage } from '../lib/types';
import { useRequestGuard } from '../lib/useRequestGuard';
import { Alert, EmptyState, Field, LoadingBlock, PageHeading, StatusBadge } from '../components/ui';
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
    const [guestsError, setGuestsError] = useState<string | null>(null);

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

    const beginContactRequest = useRequestGuard();
    const beginGuestsRequest = useRequestGuard();

    const loadContact = useCallback(async () => {
        const { signal, isCurrent } = beginContactRequest();
        setLoading(true);
        setError(null);
        try {
            const { data } = await api.get<Contact>(`/api/v1/contacts/${contactId}`, { signal });
            if (!isCurrent()) return;
            setContact(data);
        } catch (err) {
            if (signal.aborted || !isCurrent()) return;
            setError(apiErrorMessage(err));
        } finally {
            if (isCurrent()) setLoading(false);
        }
    }, [beginContactRequest, contactId]);

    const loadGuests = useCallback(async (page = 1) => {
        const { signal, isCurrent } = beginGuestsRequest();
        setGuestsLoading(true);
        setGuestsError(null);
        try {
            const { data } = await api.get<ContactGuestPage>(`/api/v1/contacts/${contactId}/guests`, {
                params: { page },
                signal,
            });
            if (!isCurrent()) return;
            setGuests(data.data);
            setGuestPage(data);
        } catch (err) {
            if (signal.aborted || !isCurrent()) return;
            setGuestsError(apiErrorMessage(err));
        } finally {
            if (isCurrent()) setGuestsLoading(false);
        }
    }, [beginGuestsRequest, contactId]);

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
    if (!contact) {
        return (
            <section className="page-state page-state-error" aria-label="Contact could not be loaded">
                <Alert kind="error">{error ?? 'Unable to load this contact.'}</Alert>
                <button type="button" className="btn btn-ghost" onClick={() => void loadContact()}>
                    Try again
                </button>
                <Link to="/contacts" className="muted">Back to contacts</Link>
            </section>
        );
    }

    return (
        <div className="stack contact-detail-page">
            <Link to="/contacts" className="detail-back-link">Back to contacts</Link>
            <PageHeading
                title={contact.name}
                description={
                    <span className="contact-heading-meta">
                        <span>{contact.email || 'No email address'}</span>
                        <span>{contact.phone || 'No phone number'}</span>
                    </span>
                }
                actions={
                    <div className="row">
                    <button type="button" className="btn btn-ghost" onClick={openEdit}>
                        Edit contact
                    </button>
                    <button type="button" className="btn btn-danger-solid" onClick={() => setDeleteOpen(true)}>
                        Delete contact
                    </button>
                    </div>
                }
            />

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error ? <Alert kind="error">{error}</Alert> : null}

            <section className="contact-detail-summary" aria-labelledby="contact-details-title">
                <h2 id="contact-details-title">Contact details</h2>
                <dl className="details-list contact-detail-list">
                    <div><dt>Email</dt><dd className="contact-value">{contact.email || 'Not provided'}</dd></div>
                    <div><dt>Phone</dt><dd className="contact-value">{contact.phone || 'Not provided'}</dd></div>
                    <div><dt>Notes</dt><dd className="contact-value contact-notes">{contact.notes || 'No notes'}</dd></div>
                    <div><dt>Linked guest records</dt><dd>{contact.guests_count}</dd></div>
                </dl>
                <p className="form-help">
                    A contact can be linked to guests in multiple events. Editing this record does not change those guest details.
                </p>
            </section>

            <section className="contact-guests-section" aria-labelledby="linked-guests-title">
                <div className="section-head">
                    <div>
                        <h2 id="linked-guests-title">Event guest records</h2>
                        <p className="muted section-sub">
                            Each row belongs to one event; RSVP and event details remain event-specific.
                        </p>
                    </div>
                </div>
                {guestsLoading ? (
                    <LoadingBlock label="Loading linked guests…" />
                ) : guestsError ? (
                    <section className="page-state page-state-error" aria-label="Linked guests could not be loaded">
                        <Alert kind="error">{guestsError}</Alert>
                        <button type="button" className="btn btn-ghost" onClick={() => void loadGuests(guestPage?.current_page ?? 1)}>
                            Try again
                        </button>
                    </section>
                ) : guests.length === 0 ? (
                    <EmptyState
                        title="No linked guest records"
                        description="This contact is not currently linked to any event guest records."
                    />
                ) : (
                    <div className="table-wrap contact-guests-table-wrap">
                        <table>
                            <caption className="visually-hidden">Event guest records linked to {contact.name}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Guest</th>
                                    <th scope="col" className="col-hide-sm">Event</th>
                                    <th scope="col" className="col-hide-sm">Event date</th>
                                    <th scope="col">RSVP</th>
                                </tr>
                            </thead>
                            <tbody>
                                {guests.map((guest) => (
                                    <tr key={guest.id}>
                                        <td className="cell-strong">
                                            <span className="contact-guest-name">{guest.name}</span>
                                            {guest.event ? (
                                                <Link className="contact-mobile-event" to={`/events/${guest.event.id}`}>
                                                    {guest.event.title}
                                                </Link>
                                            ) : null}
                                        </td>
                                        <td className="muted col-hide-sm contact-value">
                                            {guest.event ? <Link to={`/events/${guest.event.id}`}>{guest.event.title}</Link> : '—'}
                                        </td>
                                        <td className="muted col-hide-sm contact-value">
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
                    <nav className="row between pagination" aria-label="Linked guests pagination">
                        <span className="muted">
                            Page {guestPage.current_page} of {guestPage.last_page} · {guestPage.total ?? 0} guest record
                            {(guestPage.total ?? 0) === 1 ? '' : 's'}
                        </span>
                        <div className="row">
                            <button
                                type="button"
                                className="btn btn-sm btn-ghost"
                                disabled={!guestPage.prev_page_url || guestsLoading}
                                onClick={() => void loadGuests(guestPage.current_page - 1)}
                            >
                                Previous
                            </button>
                            <button
                                type="button"
                                className="btn btn-sm btn-ghost"
                                disabled={!guestPage.next_page_url || guestsLoading}
                                onClick={() => void loadGuests(guestPage.current_page + 1)}
                            >
                                Next
                            </button>
                        </div>
                    </nav>
                ) : null}
            </section>

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
