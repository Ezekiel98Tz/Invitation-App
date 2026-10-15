import { useCallback, useEffect, useRef, useState } from 'react';
import { api, apiErrorMessage, validationErrors } from '../lib/api';
import type { Guest, GuestImport, GuestPage } from '../lib/types';
import { Alert, LoadingBlock, StatusBadge } from './ui';
import { ActionMenu } from './ActionMenu';
import { Modal } from './Modal';
import { ConfirmDialog } from './ConfirmDialog';

const ALLOWED_EXTENSIONS = ['csv', 'xlsx', 'ods'];
const IMPORT_MAX_MB = 5;

interface Props {
    eventId: string;
    eventTitle?: string;
}

interface GuestForm {
    id: number | null;
    name: string;
    email: string;
    phone: string;
}

const emptyForm: GuestForm = { id: null, name: '', email: '', phone: '' };

function filenameFromDisposition(disposition: string | undefined, fallback: string): string {
    if (!disposition) return fallback;
    const match = /filename\*?=(?:UTF-8'')?["']?([^"';]+)/i.exec(disposition);
    return match ? decodeURIComponent(match[1]) : fallback;
}

async function describeError(err: unknown, fallback: string): Promise<string> {
    const response = (err as { response?: { data?: unknown } })?.response;
    if (response?.data instanceof Blob) {
        try {
            const json = JSON.parse(await response.data.text()) as { message?: string };
            if (json?.message) return json.message;
        } catch {
            // not JSON
        }
    }
    return apiErrorMessage(err) || fallback;
}

function formatWhen(iso: string | null | undefined): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString();
}

export function GuestsPanel({ eventId, eventTitle }: Props) {
    const [guests, setGuests] = useState<Guest[]>([]);
    const [pagination, setPagination] = useState<GuestPage | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [toast, setToast] = useState<string | null>(null);

    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [appliedSearch, setAppliedSearch] = useState('');
    const [appliedStatus, setAppliedStatus] = useState('');

    const [exporting, setExporting] = useState(false);
    const [remindingId, setRemindingId] = useState<number | null>(null);
    const [resendingId, setResendingId] = useState<number | null>(null);

    const [formOpen, setFormOpen] = useState(false);
    const [form, setForm] = useState<GuestForm>(emptyForm);
    const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);

    const [detailsGuest, setDetailsGuest] = useState<Guest | null>(null);
    const [linkGuest, setLinkGuest] = useState<Guest | null>(null);
    const [deleteTarget, setDeleteTarget] = useState<Guest | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [remindersOpen, setRemindersOpen] = useState(false);
    const [remindersBusy, setRemindersBusy] = useState(false);

    const [importOpen, setImportOpen] = useState(false);
    const [importFile, setImportFile] = useState<File | null>(null);
    const [importing, setImporting] = useState(false);
    const [importError, setImportError] = useState<string | null>(null);
    const [importStatus, setImportStatus] = useState<GuestImport | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const notify = useCallback((message: string) => {
        setToast(message);
        window.setTimeout(() => setToast(null), 3500);
    }, []);

    const fetchGuests = useCallback(
        async (page: number, s: string, st: string) => {
            setLoading(true);
            setError(null);
            try {
                const { data } = await api.get<GuestPage>(`/api/v1/events/${eventId}/guests`, {
                    params: { page, search: s, status: st },
                });
                setGuests(data.data);
                setPagination(data);
            } catch (err) {
                setError(apiErrorMessage(err));
            } finally {
                setLoading(false);
            }
        },
        [eventId],
    );

    useEffect(() => {
        void fetchGuests(1, '', '');
    }, [fetchGuests]);

    const counts = pagination?.counts;
    const total = counts?.total ?? pagination?.total ?? 0;
    const hasFilters = appliedSearch !== '' || appliedStatus !== '';
    const currentPage = pagination?.current_page ?? 1;

    const refresh = () => fetchGuests(currentPage, appliedSearch, appliedStatus);

    const applyFilters = () => {
        setAppliedSearch(search);
        setAppliedStatus(status);
        void fetchGuests(1, search, status);
    };

    const clearFilters = () => {
        setSearch('');
        setStatus('');
        setAppliedSearch('');
        setAppliedStatus('');
        void fetchGuests(1, '', '');
    };

    // ---- Guest form (add / edit) ----
    const openCreate = () => {
        setForm(emptyForm);
        setFormErrors({});
        setFormOpen(true);
    };

    const openEdit = (guest: Guest) => {
        setForm({ id: guest.id, name: guest.name, email: guest.email ?? '', phone: guest.phone ?? '' });
        setFormErrors({});
        setFormOpen(true);
    };

    const submitForm = async (e: React.FormEvent) => {
        e.preventDefault();
        setSaving(true);
        setFormErrors({});
        const payload = {
            name: form.name,
            email: form.email.trim() === '' ? null : form.email,
            phone: form.phone.trim() === '' ? null : form.phone,
        };
        try {
            if (form.id === null) {
                await api.post(`/api/v1/events/${eventId}/guests`, payload);
                notify(`${form.name} added.`);
            } else {
                await api.put(`/api/v1/events/${eventId}/guests/${form.id}`, payload);
                notify('Guest updated.');
            }
            setFormOpen(false);
            await refresh();
        } catch (err) {
            setFormErrors(validationErrors(err));
            if (!Object.keys(validationErrors(err)).length) {
                setError(apiErrorMessage(err));
            }
        } finally {
            setSaving(false);
        }
    };

    // ---- Row actions ----
    const resend = async (guest: Guest) => {
        setResendingId(guest.id);
        try {
            await api.post(`/api/v1/events/${eventId}/guests/${guest.id}/resend`);
            notify(`Invitation resend queued for ${guest.name}.`);
            await refresh();
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setResendingId(null);
        }
    };

    const remind = async (guest: Guest) => {
        setRemindingId(guest.id);
        try {
            await api.post(`/api/v1/events/${eventId}/guests/${guest.id}/remind`);
            notify(`Reminder queued for ${guest.name}.`);
            await refresh();
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setRemindingId(null);
        }
    };

    const confirmDelete = async () => {
        if (!deleteTarget) return;
        setDeleting(true);
        try {
            await api.delete(`/api/v1/events/${eventId}/guests/${deleteTarget.id}`);
            const name = deleteTarget.name;
            setDeleteTarget(null);
            notify(`${name} removed.`);
            await refresh();
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setDeleting(false);
        }
    };

    const confirmReminders = async () => {
        setRemindersBusy(true);
        try {
            const { data } = await api.post<{ queued: number }>(`/api/v1/events/${eventId}/guests/reminders`);
            setRemindersOpen(false);
            notify(
                data.queued > 0
                    ? `Reminders queued for ${data.queued} eligible guest(s).`
                    : 'No guests were eligible for a reminder.',
            );
            await refresh();
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setRemindersBusy(false);
        }
    };

    // ---- Export ----
    const exportGuests = async () => {
        setExporting(true);
        setError(null);
        try {
            const response = await api.get(`/api/v1/events/${eventId}/guests/export`, { responseType: 'blob' });
            const contentType = String(response.headers['content-type'] ?? '');
            const blob = response.data as Blob;

            if (blob.size === 0) throw new Error('The export returned an empty file.');
            if (!/spreadsheet|officedocument|ms-excel|octet-stream/i.test(contentType)) {
                let message = 'The export did not return a spreadsheet.';
                try {
                    const parsed = JSON.parse(await blob.text()) as { message?: string };
                    if (parsed?.message) message = parsed.message;
                } catch {
                    // keep default
                }
                throw new Error(message);
            }

            const filename = filenameFromDisposition(
                String(response.headers['content-disposition'] ?? ''),
                `guests-${eventId}.xlsx`,
            );
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = filename;
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            URL.revokeObjectURL(url);
            notify(`Downloaded ${filename}.`);
        } catch (err) {
            setError(await describeError(err, 'Export failed.'));
        } finally {
            setExporting(false);
        }
    };

    // ---- Import ----
    const openImport = () => {
        setImportFile(null);
        setImportError(null);
        setImportStatus(null);
        setImportOpen(true);
    };

    const onFileSelected = (file: File | undefined) => {
        setImportError(null);
        if (!file) {
            setImportFile(null);
            return;
        }
        const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
        if (!ALLOWED_EXTENSIONS.includes(ext)) {
            setImportFile(null);
            setImportError(`Unsupported file type ".${ext}". Choose a .csv, .xlsx, or .ods file.`);
            return;
        }
        if (file.size > IMPORT_MAX_MB * 1024 * 1024) {
            setImportFile(null);
            setImportError(`That file is too large. The maximum size is ${IMPORT_MAX_MB} MB.`);
            return;
        }
        setImportFile(file);
    };

    const submitImport = async () => {
        if (!importFile) return;
        setImporting(true);
        setImportError(null);
        try {
            const form = new FormData();
            form.append('file', importFile);
            form.append('mapping[name]', 'name');
            form.append('mapping[email]', 'email');
            form.append('mapping[phone]', 'phone');
            const { data } = await api.post<{ import: GuestImport }>(
                `/api/v1/events/${eventId}/guests/import`,
                form,
                { headers: { 'Content-Type': 'multipart/form-data' } },
            );
            setImportFile(null);
            setImportStatus(data.import);
        } catch (err) {
            setImportError(await describeError(err, 'Import failed.'));
        } finally {
            setImporting(false);
        }
    };

    // Poll the latest import until it reaches a terminal state.
    useEffect(() => {
        if (!importStatus || importStatus.status === 'completed' || importStatus.status === 'failed') {
            return;
        }
        const timer = window.setTimeout(async () => {
            try {
                const { data } = await api.get<{ import: GuestImport | null }>(
                    `/api/v1/events/${eventId}/imports/latest`,
                );
                if (data.import) {
                    setImportStatus(data.import);
                    if (data.import.status === 'completed') {
                        await fetchGuests(1, appliedSearch, appliedStatus);
                        notify('Import completed.');
                    }
                }
            } catch {
                // transient error; the next poll will retry
            }
        }, 2500);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [importStatus, eventId]);

    const importTerminal = importStatus?.status === 'completed' || importStatus?.status === 'failed';

    return (
        <section className="guests-panel">
            <header className="section-head">
                <div>
                    <h2>Guests</h2>
                    <p className="muted section-sub">
                        {total === 1 ? '1 guest' : `${total} guests`} for this event
                    </p>
                    {counts ? (
                        <div className="counts" aria-label="Guest status counts">
                            <span className="count">
                                <strong>{counts.total}</strong> total
                            </span>
                            <span className="count pending">
                                <strong>{counts.pending}</strong> pending
                            </span>
                            <span className="count accepted">
                                <strong>{counts.accepted}</strong> accepted
                            </span>
                            <span className="count declined">
                                <strong>{counts.declined}</strong> declined
                            </span>
                        </div>
                    ) : null}
                </div>
                <div className="row actions">
                    <button type="button" className="btn btn-primary" onClick={openCreate}>
                        Add guest
                    </button>
                    <button type="button" className="btn btn-ghost" onClick={openImport}>
                        Import guests
                    </button>
                    <button type="button" className="btn btn-ghost" onClick={exportGuests} disabled={exporting}>
                        {exporting ? 'Exporting…' : 'Export guests'}
                    </button>
                    <button type="button" className="btn btn-ghost" onClick={() => setRemindersOpen(true)}>
                        Send reminders
                    </button>
                </div>
            </header>

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error ? <Alert kind="error">{error}</Alert> : null}

            <div className="toolbar">
                <div className="search-field">
                    <label className="label" htmlFor="guest-search">
                        Search
                    </label>
                    <input
                        id="guest-search"
                        className="input"
                        type="search"
                        placeholder="Name or email"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                    />
                </div>
                <div className="filter-field">
                    <label className="label" htmlFor="guest-status">
                        Status
                    </label>
                    <select
                        id="guest-status"
                        className="select"
                        value={status}
                        onChange={(e) => setStatus(e.target.value)}
                    >
                        <option value="">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="accepted">Accepted</option>
                        <option value="declined">Declined</option>
                    </select>
                </div>
                <button type="button" className="btn btn-ghost" onClick={applyFilters}>
                    Apply
                </button>
                {hasFilters ? (
                    <button type="button" className="btn btn-link" onClick={clearFilters}>
                        Clear
                    </button>
                ) : null}
            </div>

            {loading ? (
                <div className="table-wrap" aria-hidden="true">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th className="col-hide-sm">Email</th>
                                <th>RSVP</th>
                                <th className="col-hide-sm">Invited</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {[0, 1, 2, 3].map((i) => (
                                <tr key={i}>
                                    <td><span className="skeleton" style={{ width: '60%' }} /></td>
                                    <td className="col-hide-sm"><span className="skeleton" style={{ width: '70%' }} /></td>
                                    <td><span className="skeleton" style={{ width: 56 }} /></td>
                                    <td className="col-hide-sm"><span className="skeleton" style={{ width: '50%' }} /></td>
                                    <td />
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : guests.length === 0 ? (
                <div className="empty-state">
                    {hasFilters ? (
                        <>
                            <h3>No guests match these filters</h3>
                            <p className="muted">Try a different search or status, or clear the filters.</p>
                            <button type="button" className="btn btn-ghost" onClick={clearFilters}>
                                Clear filters
                            </button>
                        </>
                    ) : (
                        <>
                            <h3>No guests yet</h3>
                            <p className="muted">Add a guest manually or import a list to get started.</p>
                            <div className="row" style={{ justifyContent: 'center' }}>
                                <button type="button" className="btn btn-primary" onClick={openCreate}>
                                    Add guest
                                </button>
                                <button type="button" className="btn btn-ghost" onClick={openImport}>
                                    Import guests
                                </button>
                            </div>
                        </>
                    )}
                </div>
            ) : (
                <div className="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th className="col-hide-sm">Email</th>
                                <th className="col-hide-sm">Phone</th>
                                <th>RSVP</th>
                                <th>Attending</th>
                                <th className="col-hide-sm">Invited</th>
                                <th style={{ textAlign: 'right' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {guests.map((g) => {
                                const canRemind = g.status === 'pending' && !g.reminded_at;
                                return (
                                    <tr key={g.id}>
                                        <td className="cell-strong">
                                            {g.name}
                                            {g.reminded_at ? (
                                                <span className="row-tag" title={`Reminded ${formatWhen(g.reminded_at)}`}>
                                                    reminded
                                                </span>
                                            ) : null}
                                        </td>
                                        <td className="muted col-hide-sm">{g.email || '—'}</td>
                                        <td className="muted col-hide-sm">{g.phone || '—'}</td>
                                        <td>
                                            <StatusBadge status={g.status} />
                                        </td>
                                        <td className="muted">
                                            {g.status === 'accepted' ? (g.rsvp?.attending_count ?? 1) : '—'}
                                        </td>
                                        <td className="muted col-hide-sm">
                                            {g.sent_at ? new Date(g.sent_at).toLocaleDateString() : 'Not sent'}
                                        </td>
                                        <td style={{ textAlign: 'right' }}>
                                            <ActionMenu
                                                label={`Actions for ${g.name}`}
                                                items={[
                                                    { label: 'View details', onSelect: () => setDetailsGuest(g) },
                                                    { label: 'Edit guest', onSelect: () => openEdit(g) },
                                                    { label: 'Invitation link', onSelect: () => setLinkGuest(g) },
                                                    { label: 'Resend invitation', onSelect: () => resend(g) },
                                                    ...(canRemind
                                                        ? [{ label: 'Send reminder', onSelect: () => remind(g) }]
                                                        : []),
                                                    { label: 'Delete guest', onSelect: () => setDeleteTarget(g), danger: true },
                                                ]}
                                            />
                                            {resendingId === g.id || remindingId === g.id ? (
                                                <span className="muted small"> working…</span>
                                            ) : null}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            {pagination && pagination.last_page > 1 ? (
                <div className="row between pagination">
                    <span className="muted">
                        Page {pagination.current_page} of {pagination.last_page}
                    </span>
                    <div className="row">
                        <button
                            type="button"
                            className="btn btn-sm btn-ghost"
                            disabled={!pagination.prev_page_url}
                            onClick={() => fetchGuests(pagination.current_page - 1, appliedSearch, appliedStatus)}
                        >
                            Previous
                        </button>
                        <button
                            type="button"
                            className="btn btn-sm btn-ghost"
                            disabled={!pagination.next_page_url}
                            onClick={() => fetchGuests(pagination.current_page + 1, appliedSearch, appliedStatus)}
                        >
                            Next
                        </button>
                    </div>
                </div>
            ) : null}

            {/* Add / edit guest */}
            <Modal
                open={formOpen}
                title={form.id === null ? 'Add guest' : 'Edit guest'}
                onClose={() => setFormOpen(false)}
                busy={saving}
                maxWidth={480}
            >
                <form onSubmit={submitForm}>
                    <div className="field">
                        <label className="label" htmlFor="guest-name">
                            Name
                        </label>
                        <input
                            id="guest-name"
                            className="input"
                            value={form.name}
                            onChange={(e) => setForm({ ...form, name: e.target.value })}
                            maxLength={255}
                            required
                        />
                        {formErrors.name?.[0] ? <div className="error-text">{formErrors.name[0]}</div> : null}
                    </div>
                    <div className="field">
                        <label className="label" htmlFor="guest-email">
                            Email <span className="muted">(optional)</span>
                        </label>
                        <input
                            id="guest-email"
                            className="input"
                            type="email"
                            value={form.email}
                            onChange={(e) => setForm({ ...form, email: e.target.value })}
                        />
                        {formErrors.email?.[0] ? <div className="error-text">{formErrors.email[0]}</div> : null}
                    </div>
                    <div className="field">
                        <label className="label" htmlFor="guest-phone">
                            Phone <span className="muted">(optional)</span>
                        </label>
                        <input
                            id="guest-phone"
                            className="input"
                            value={form.phone}
                            onChange={(e) => setForm({ ...form, phone: e.target.value })}
                        />
                        {formErrors.phone?.[0] ? <div className="error-text">{formErrors.phone[0]}</div> : null}
                    </div>
                    <p className="form-help">
                        Saving a guest does not send an invitation. Use “Resend invitation” to deliver one.
                    </p>
                    <div className="modal-footer">
                        <button type="button" className="btn btn-ghost" onClick={() => setFormOpen(false)} disabled={saving}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={saving || form.name.trim() === ''}>
                            {saving ? 'Saving…' : form.id === null ? 'Add guest' : 'Save changes'}
                        </button>
                    </div>
                </form>
            </Modal>

            {/* Guest details */}
            <Modal
                open={detailsGuest !== null}
                title="Guest details"
                onClose={() => setDetailsGuest(null)}
                maxWidth={480}
            >
                {detailsGuest ? (
                    <dl className="details-list">
                        <div><dt>Name</dt><dd>{detailsGuest.name}</dd></div>
                        <div><dt>Email</dt><dd>{detailsGuest.email || '—'}</dd></div>
                        <div><dt>Phone</dt><dd>{detailsGuest.phone || '—'}</dd></div>
                        <div><dt>RSVP</dt><dd><StatusBadge status={detailsGuest.status} /></dd></div>
                        {detailsGuest.status === 'accepted' ? (
                            <div><dt>Attending</dt><dd>{detailsGuest.rsvp?.attending_count ?? 1}</dd></div>
                        ) : null}
                        {detailsGuest.rsvp?.answers && Object.keys(detailsGuest.rsvp.answers).length > 0 ? (
                            <div>
                                <dt>Answers</dt>
                                <dd>
                                    {Object.entries(detailsGuest.rsvp.answers).map(([k, v]) => (
                                        <div key={k}>
                                            <span className="muted">{k}:</span> {String(v)}
                                        </div>
                                    ))}
                                </dd>
                            </div>
                        ) : null}
                        <div><dt>Invited</dt><dd>{detailsGuest.sent_at ? formatWhen(detailsGuest.sent_at) : 'Not sent'}</dd></div>
                        <div><dt>Reminded</dt><dd>{detailsGuest.reminded_at ? formatWhen(detailsGuest.reminded_at) : 'Not reminded'}</dd></div>
                    </dl>
                ) : null}
                <div className="modal-footer">
                    <button type="button" className="btn btn-ghost" onClick={() => setDetailsGuest(null)}>
                        Close
                    </button>
                    {detailsGuest ? (
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => {
                                const g = detailsGuest;
                                setDetailsGuest(null);
                                openEdit(g);
                            }}
                        >
                            Edit guest
                        </button>
                    ) : null}
                </div>
            </Modal>

            {/* Invitation link + QR */}
            <InviteLinkModal guest={linkGuest} onClose={() => setLinkGuest(null)} onNotify={notify} />

            {/* Delete confirmation */}
            <ConfirmDialog
                open={deleteTarget !== null}
                title="Delete guest"
                message={
                    <>
                        Remove <strong>{deleteTarget?.name}</strong> from this event? Their RSVP and delivery
                        history will be deleted. This cannot be undone.
                    </>
                }
                confirmLabel="Delete guest"
                danger
                busy={deleting}
                onConfirm={confirmDelete}
                onCancel={() => setDeleteTarget(null)}
            />

            {/* Reminders confirmation */}
            <ConfirmDialog
                open={remindersOpen}
                title="Send reminders"
                message={
                    <>
                        Reminders are sent to <strong>pending</strong> guests who were invited more than{' '}
                        <strong>2 days ago</strong> and have not been reminded yet. Eligible guests receive one
                        reminder each.
                    </>
                }
                confirmLabel="Send reminders"
                busy={remindersBusy}
                onConfirm={confirmReminders}
                onCancel={() => setRemindersOpen(false)}
            />

            {/* Import dialog */}
            <Modal open={importOpen} title="Import guests" onClose={() => !importing && setImportOpen(false)} busy={importing} maxWidth={520}>
                {importStatus ? (
                    <div className="stack">
                        <div className={`status-chip status-${importStatus.status}`}>
                            {importStatus.status === 'queued' ? 'Queued' : null}
                            {importStatus.status === 'processing' ? 'Processing…' : null}
                            {importStatus.status === 'completed' ? 'Completed' : null}
                            {importStatus.status === 'failed' ? 'Failed' : null}
                        </div>
                        {importStatus.status === 'completed' ? (
                            <div className="stack">
                                <p className="muted" style={{ margin: 0 }}>
                                    Added <strong>{importStatus.created_count}</strong> guest(s); skipped{' '}
                                    <strong>{importStatus.skipped_count}</strong> duplicate or blank row(s).
                                </p>
                                {importStatus.failed_count > 0 ? (
                                    <Alert kind="error">
                                        <strong>{importStatus.failed_count}</strong> row(s) were not imported because
                                        their email and phone matched different contacts. Fix the conflicting
                                        contact(s) — then re-import the file to add those guests.
                                    </Alert>
                                ) : null}
                            </div>
                        ) : null}
                        {importStatus.status === 'failed' ? (
                            <Alert kind="error">
                                {importStatus.error ?? 'The import could not be completed. Please try again.'}
                            </Alert>
                        ) : null}
                        {!importTerminal ? (
                            <p className="muted small">
                                Imports run in the background. This updates automatically; you will also be emailed
                                when it finishes.
                            </p>
                        ) : null}
                        <div className="modal-footer">
                            <button type="button" className="btn btn-ghost" onClick={() => setImportOpen(false)}>
                                Close
                            </button>
                            {importStatus.status === 'failed' ? (
                                <button
                                    type="button"
                                    className="btn btn-primary"
                                    onClick={() => {
                                        setImportStatus(null);
                                        setImportError(null);
                                    }}
                                >
                                    Try another file
                                </button>
                            ) : null}
                        </div>
                    </div>
                ) : (
                    <>
                        <p className="muted" style={{ marginTop: 0 }}>
                            Import guests into <strong>{eventTitle || 'this event'}</strong>.
                        </p>
                        <ul className="help-list">
                            <li>
                                Supported files: <strong>.csv</strong>, <strong>.xlsx</strong>, or <strong>.ods</strong>{' '}
                                (up to {IMPORT_MAX_MB} MB).
                            </li>
                            <li>
                                The first row must be a header row with a <strong>name</strong> column.{' '}
                                <strong>email</strong> and <strong>phone</strong> columns are optional.
                            </li>
                            <li>Rows without a name are skipped.</li>
                            <li>
                                Guests that duplicate an existing guest (same email, or same name when no email) are
                                skipped — imports never create duplicates.
                            </li>
                            <li>Imports run in the background; you will be emailed when it finishes.</li>
                        </ul>

                        <div className="file-drop">
                            <input
                                ref={fileInputRef}
                                id="guest-import-file"
                                type="file"
                                accept=".csv,.xlsx,.ods"
                                className="visually-hidden"
                                onChange={(e) => onFileSelected(e.target.files?.[0])}
                            />
                            {importFile ? (
                                <div className="row between">
                                    <span className="cell-strong">{importFile.name}</span>
                                    <button
                                        type="button"
                                        className="btn btn-sm btn-ghost"
                                        onClick={() => fileInputRef.current?.click()}
                                        disabled={importing}
                                    >
                                        Change file
                                    </button>
                                </div>
                            ) : (
                                <button
                                    type="button"
                                    className="btn btn-ghost"
                                    onClick={() => fileInputRef.current?.click()}
                                    disabled={importing}
                                >
                                    Choose file
                                </button>
                            )}
                        </div>

                        {importError ? <Alert kind="error">{importError}</Alert> : null}

                        <div className="modal-footer">
                            <button type="button" className="btn btn-ghost" onClick={() => setImportOpen(false)} disabled={importing}>
                                Cancel
                            </button>
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={submitImport}
                                disabled={importing || !importFile}
                            >
                                {importing ? 'Importing…' : 'Import'}
                            </button>
                        </div>
                    </>
                )}
            </Modal>
        </section>
    );
}

function InviteLinkModal({
    guest,
    onClose,
    onNotify,
}: {
    guest: Guest | null;
    onClose: () => void;
    onNotify: (message: string) => void;
}) {
    const [qrFailed, setQrFailed] = useState(false);
    const [copyState, setCopyState] = useState<'idle' | 'copied' | 'error'>('idle');
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        setQrFailed(false);
        setCopyState('idle');
    }, [guest?.id]);

    const inviteUrl =
        guest && guest.invite_token ? `${window.location.origin}/invite/${guest.invite_token}` : '';

    const copy = async () => {
        if (!inviteUrl) return;
        try {
            await navigator.clipboard.writeText(inviteUrl);
            setCopyState('copied');
            onNotify('Invitation link copied.');
        } catch {
            setCopyState('error');
            inputRef.current?.focus();
            inputRef.current?.select();
        }
    };

    return (
        <Modal open={guest !== null} title="Invitation link" onClose={onClose} maxWidth={520}>
            {guest ? (
                <div className="stack">
                    {inviteUrl ? (
                        <>
                            <p className="muted" style={{ marginTop: 0 }}>
                                Guest-specific link for <strong>{guest.name}</strong>. It stays valid until the event
                                starts.
                            </p>
                            <div className="row">
                                <input
                                    ref={inputRef}
                                    className="input"
                                    readOnly
                                    value={inviteUrl}
                                    onFocus={(e) => e.currentTarget.select()}
                                    aria-label="Invitation link"
                                />
                                <button type="button" className="btn btn-primary" onClick={copy}>
                                    {copyState === 'copied' ? 'Copied' : 'Copy'}
                                </button>
                            </div>
                            {copyState === 'error' ? (
                                <Alert kind="error">
                                    Could not copy automatically — select the link and copy it manually.
                                </Alert>
                            ) : null}
                            <div className="row" style={{ justifyContent: 'center' }}>
                                {qrFailed ? (
                                    <Alert kind="error">The QR code could not be loaded.</Alert>
                                ) : (
                                    <img
                                        src={`/invite/${guest.invite_token}/qr`}
                                        alt={`QR code for ${guest.name}'s invitation`}
                                        width={200}
                                        height={200}
                                        onError={() => setQrFailed(true)}
                                        style={{
                                            background: '#fff',
                                            border: '1px solid #e2e8f0',
                                            borderRadius: 8,
                                        }}
                                    />
                                )}
                            </div>
                            <a
                                className="btn btn-ghost"
                                href={inviteUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Open invitation
                            </a>
                        </>
                    ) : (
                        <Alert kind="error">This guest has no invitation link.</Alert>
                    )}
                    <div className="modal-footer">
                        <button type="button" className="btn btn-ghost" onClick={onClose}>
                            Close
                        </button>
                    </div>
                </div>
            ) : null}
        </Modal>
    );
}
