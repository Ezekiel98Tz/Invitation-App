import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, apiErrorMessage, validationErrors } from '../lib/api';
import type { Contact, ContactPage } from '../lib/types';
import { useRequestGuard } from '../lib/useRequestGuard';
import { Alert, EmptyState, Field, LoadingBlock, PageHeading } from '../components/ui';
import { Modal } from '../components/Modal';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { ActionMenu } from '../components/ActionMenu';

const DEFAULT_PAGE_SIZE = 20;
const PAGE_SIZE_OPTIONS = [20, 50, 100] as const;

interface ContactForm {
    id: number | null;
    name: string;
    email: string;
    phone: string;
    notes: string;
}

const emptyForm: ContactForm = { id: null, name: '', email: '', phone: '', notes: '' };

function formFromContact(contact: Contact): ContactForm {
    return {
        id: contact.id,
        name: contact.name,
        email: contact.email ?? '',
        phone: contact.phone ?? '',
        notes: contact.notes ?? '',
    };
}

export function ContactsPage() {
    const [contacts, setContacts] = useState<Contact[]>([]);
    const [pagination, setPagination] = useState<ContactPage | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [toast, setToast] = useState<string | null>(null);

    const [search, setSearch] = useState('');
    const [appliedSearch, setAppliedSearch] = useState('');
    const [pageSize, setPageSize] = useState<number>(DEFAULT_PAGE_SIZE);

    const [modalOpen, setModalOpen] = useState(false);
    const [form, setForm] = useState<ContactForm>(emptyForm);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);

    const [deleteTarget, setDeleteTarget] = useState<Contact | null>(null);
    const [deleting, setDeleting] = useState(false);

    const flash = (message: string) => {
        setToast(message);
        window.setTimeout(() => setToast(null), 3500);
    };

    const beginRequest = useRequestGuard();

    const load = useCallback(
        async (page: number, term: string, size: number) => {
            const { signal, isCurrent } = beginRequest();
            setLoading(true);
            setError(null);
            try {
                const { data } = await api.get<ContactPage>('/api/v1/contacts', {
                    params: { page, search: term, per_page: size },
                    signal,
                });
                // Ignore a response a newer request has superseded: out-of-order
                // replies must never overwrite fresher results.
                if (!isCurrent()) return;
                setContacts(data.data);
                setPagination(data);
            } catch (err) {
                // A cancelled request is expected when the user pages/searches
                // quickly; never surface it as an error.
                if (signal.aborted || !isCurrent()) return;
                setError(apiErrorMessage(err));
            } finally {
                if (isCurrent()) setLoading(false);
            }
        },
        [beginRequest],
    );

    useEffect(() => {
        void load(1, '', DEFAULT_PAGE_SIZE);
    }, [load]);

    const applySearch = (event?: React.FormEvent) => {
        event?.preventDefault();
        const term = search.trim();
        setAppliedSearch(term);
        void load(1, term, pageSize);
    };

    const clearFilters = () => {
        setSearch('');
        setAppliedSearch('');
        void load(1, '', pageSize);
    };

    const changePageSize = (size: number) => {
        setPageSize(size);
        void load(1, appliedSearch, size);
    };

    const openEdit = (contact: Contact) => {
        setForm(formFromContact(contact));
        setErrors({});
        setModalOpen(true);
    };

    const save = async (e: React.FormEvent) => {
        e.preventDefault();
        if (form.id === null) return;
        setSaving(true);
        setErrors({});
        const payload = {
            name: form.name,
            email: form.email.trim() === '' ? null : form.email,
            phone: form.phone.trim() === '' ? null : form.phone,
            notes: form.notes.trim() === '' ? null : form.notes,
        };
        try {
            await api.patch(`/api/v1/contacts/${form.id}`, payload);
            setModalOpen(false);
            flash('Contact updated.');
            await load(pagination?.current_page ?? 1, appliedSearch, pageSize);
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
            const { data } = await api.delete<{ detached_guests: number }>(
                `/api/v1/contacts/${deleteTarget.id}`,
            );
            const name = deleteTarget.name;
            setDeleteTarget(null);
            flash(
                data.detached_guests > 0
                    ? `"${name}" deleted. ${data.detached_guests} guest(s) were kept and detached.`
                    : `"${name}" deleted.`,
            );
            await load(pagination?.current_page ?? 1, appliedSearch, pageSize);
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setDeleting(false);
        }
    };

    const hasFilters = appliedSearch !== '';
    const initialLoadError = !loading && error !== null && contacts.length === 0;

    return (
        <div className="stack">
            <PageHeading
                title="Contacts"
                description="Reusable people records linked to guests across your events. Contact details and event guest details remain separate."
            />

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error && !initialLoadError ? <Alert kind="error">{error}</Alert> : null}

            <form className="toolbar contact-search" role="search" onSubmit={applySearch}>
                <div className="search-field">
                    <label className="label" htmlFor="contact-search">
                        Find a contact
                    </label>
                    <input
                        id="contact-search"
                        className="input"
                        type="search"
                        placeholder="Name, email, or phone"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
                <button type="submit" className="btn btn-ghost" disabled={loading}>
                    Search
                </button>
                {hasFilters ? (
                    <button type="button" className="btn btn-link" onClick={clearFilters} disabled={loading}>
                        Clear search
                    </button>
                ) : null}
            </form>

            {loading ? (
                <section className="list-loading" aria-label="Loading contacts" aria-busy="true">
                    <LoadingBlock label="Loading contacts…" />
                </section>
            ) : initialLoadError ? (
                <section className="page-state page-state-error" aria-label="Contacts could not be loaded">
                    <h2>We couldn’t load contacts</h2>
                    <p className="muted">Check your connection, then try again.</p>
                    <button type="button" className="btn btn-ghost" onClick={() => void load(1, appliedSearch, pageSize)}>
                        Try again
                    </button>
                </section>
            ) : contacts.length === 0 ? (
                <EmptyState
                    title={hasFilters ? 'No contacts match this search' : 'Contacts appear as you manage guests'}
                    description={
                        hasFilters
                            ? 'Try a different name, email, or phone number, or clear the search.'
                            : 'Contacts are created through guest workflows. Add a guest to an event to start a contact record.'
                    }
                    action={
                        hasFilters ? (
                            <button type="button" className="btn btn-ghost" onClick={clearFilters}>
                                Clear search
                            </button>
                        ) : (
                            <Link className="btn btn-ghost" to="/dashboard">
                                View events
                            </Link>
                        )
                    }
                />
            ) : (
                <div className="table-wrap contact-table-wrap">
                    <table>
                        <caption className="visually-hidden">Contacts and their linked event guests</caption>
                        <thead>
                            <tr>
                                <th scope="col">Contact</th>
                                <th scope="col" className="col-hide-sm">Email</th>
                                <th scope="col" className="col-hide-sm">Phone</th>
                                <th scope="col">Linked guests</th>
                                <th scope="col" className="table-actions-heading">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {contacts.map((contact) => (
                                <tr key={contact.id}>
                                    <td className="cell-strong">
                                        <Link className="contact-name-link" to={`/contacts/${contact.id}`}>{contact.name}</Link>
                                        <span className="contact-mobile-details">
                                            <span>{contact.email || 'No email'}</span>
                                            <span>{contact.phone || 'No phone'}</span>
                                        </span>
                                    </td>
                                    <td className="muted col-hide-sm contact-value">{contact.email || '—'}</td>
                                    <td className="muted col-hide-sm contact-value">{contact.phone || '—'}</td>
                                    <td className="contact-linked-count">
                                        <span className="count">
                                            <strong>{contact.guests_count}</strong>{' '}
                                            {contact.guests_count === 1 ? 'guest' : 'guests'}
                                        </span>
                                    </td>
                                    <td className="table-actions-cell">
                                        <ActionMenu
                                            label={`Actions for ${contact.name}`}
                                            items={[
                                                { label: 'Edit contact', onSelect: () => openEdit(contact) },
                                                { label: 'Delete contact', onSelect: () => setDeleteTarget(contact), danger: true },
                                            ]}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {pagination && (pagination.total ?? 0) > 0 ? (
                <nav className="row between pagination" aria-label="Contacts pagination">
                    <div className="row" style={{ gap: '0.75rem', alignItems: 'center' }}>
                        <span className="muted">
                            Page {pagination.current_page} of {pagination.last_page} · {pagination.total ?? 0} contact
                            {(pagination.total ?? 0) === 1 ? '' : 's'}
                        </span>
                        <label
                            className="muted small"
                            htmlFor="contact-page-size"
                            style={{ display: 'inline-flex', gap: '0.35rem', alignItems: 'center' }}
                        >
                            Rows
                            <select
                                id="contact-page-size"
                                className="select"
                                style={{ width: 'auto' }}
                                value={pageSize}
                                onChange={(e) => changePageSize(Number(e.target.value))}
                                disabled={loading}
                            >
                                {PAGE_SIZE_OPTIONS.map((size) => (
                                    <option key={size} value={size}>
                                        {size}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    <div className="row">
                        <button
                            type="button"
                            className="btn btn-sm btn-ghost"
                            disabled={!pagination.prev_page_url || loading}
                            onClick={() => void load(pagination.current_page - 1, appliedSearch, pageSize)}
                        >
                            Previous
                        </button>
                        <button
                            type="button"
                            className="btn btn-sm btn-ghost"
                            disabled={!pagination.next_page_url || loading}
                            onClick={() => void load(pagination.current_page + 1, appliedSearch, pageSize)}
                        >
                            Next
                        </button>
                    </div>
                </nav>
            ) : null}

            <Modal
                open={modalOpen}
                title="Edit contact"
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
                            {saving ? 'Saving…' : 'Save changes'}
                        </button>
                    </div>
                </form>
            </Modal>

            <ConfirmDialog
                open={deleteTarget !== null}
                title="Delete contact"
                message={
                    <>
                        Delete <strong>{deleteTarget?.name}</strong>?{' '}
                        {deleteTarget && deleteTarget.guests_count > 0 ? (
                            <>
                                {deleteTarget.guests_count} linked guest record(s) will be <strong>kept</strong> but
                                detached from this contact. No guests, RSVPs, or deliveries are deleted.
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
                onCancel={() => setDeleteTarget(null)}
            />
        </div>
    );
}
