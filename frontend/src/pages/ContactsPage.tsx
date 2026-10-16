import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, apiErrorMessage, validationErrors } from '../lib/api';
import type { Contact, ContactPage } from '../lib/types';
import { useRequestGuard } from '../lib/useRequestGuard';
import { Alert, Field, LoadingBlock } from '../components/ui';
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

    const applySearch = () => {
        setAppliedSearch(search);
        void load(1, search, pageSize);
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

    return (
        <div className="stack">
            <header className="page-head">
                <div>
                    <h1>Contacts</h1>
                    <p className="muted page-sub">
                        Reusable contacts shared by your event guests. Editing a contact never changes a guest record.
                    </p>
                </div>
            </header>

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error ? <Alert kind="error">{error}</Alert> : null}

            <div className="toolbar">
                <div className="search-field">
                    <label className="label" htmlFor="contact-search">
                        Search
                    </label>
                    <input
                        id="contact-search"
                        className="input"
                        type="search"
                        placeholder="Name, email, or phone"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && applySearch()}
                    />
                </div>
                <button type="button" className="btn btn-ghost" onClick={applySearch}>
                    Apply
                </button>
                {hasFilters ? (
                    <button type="button" className="btn btn-link" onClick={clearFilters}>
                        Clear
                    </button>
                ) : null}
            </div>

            {loading ? (
                <div className="card">
                    <LoadingBlock label="Loading contacts…" />
                </div>
            ) : contacts.length === 0 ? (
                <div className="card empty-state">
                    <h3>{hasFilters ? 'No contacts match this search' : 'No contacts yet'}</h3>
                    <p className="muted">
                        {hasFilters
                            ? 'Try a different name, email, or phone number.'
                            : 'Contacts are created automatically when you add guests to an event.'}
                    </p>
                    {hasFilters ? (
                        <button type="button" className="btn btn-ghost" onClick={clearFilters}>
                            Clear search
                        </button>
                    ) : null}
                </div>
            ) : (
                <div className="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th className="col-hide-sm">Email</th>
                                <th className="col-hide-sm">Phone</th>
                                <th>Guests</th>
                                <th style={{ textAlign: 'right' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {contacts.map((contact) => (
                                <tr key={contact.id}>
                                    <td className="cell-strong">
                                        <Link to={`/contacts/${contact.id}`}>{contact.name}</Link>
                                    </td>
                                    <td className="muted col-hide-sm">{contact.email || '—'}</td>
                                    <td className="muted col-hide-sm">{contact.phone || '—'}</td>
                                    <td className="muted">{contact.guests_count}</td>
                                    <td style={{ textAlign: 'right' }}>
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
                <div className="row between pagination" aria-label="Contacts pagination">
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
                            disabled={!pagination.prev_page_url}
                            onClick={() => void load(pagination.current_page - 1, appliedSearch, pageSize)}
                        >
                            Previous
                        </button>
                        <button
                            type="button"
                            className="btn btn-sm btn-ghost"
                            disabled={!pagination.next_page_url}
                            onClick={() => void load(pagination.current_page + 1, appliedSearch, pageSize)}
                        >
                            Next
                        </button>
                    </div>
                </div>
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
