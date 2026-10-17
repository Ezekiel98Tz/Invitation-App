import { useCallback, useEffect, useRef, useState } from 'react';
import { api, apiErrorMessage, validationErrors } from '../lib/api';
import type {
    CommunicationKind,
    CredentialWithCode,
    CredentialQueuedResponse,
    Guest,
    GuestImport,
    GuestPage,
    InvitationCredential,
} from '../lib/types';
import { Alert, LoadingBlock, StatusBadge } from './ui';
import { ActionMenu } from './ActionMenu';
import { Modal } from './Modal';
import { ConfirmDialog } from './ConfirmDialog';
import { CommunicationDialog } from './CommunicationDialog';
import type { CommunicationScope } from './CommunicationDialog';
import { useRequestGuard } from '../lib/useRequestGuard';

const ALLOWED_EXTENSIONS = ['csv', 'xlsx', 'ods'];
const IMPORT_MAX_MB = 5;
const DEFAULT_PAGE_SIZE = 20;
const PAGE_SIZE_OPTIONS = [20, 50, 100] as const;

interface Props {
    eventId: string;
    eventTitle?: string;
    /** Whether the event's `check_in` capability is enabled. */
    checkInEnabled: boolean;
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

export function GuestsPanel({ eventId, eventTitle, checkInEnabled }: Props) {
    const [guests, setGuests] = useState<Guest[]>([]);
    const [pagination, setPagination] = useState<GuestPage | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [toast, setToast] = useState<string | null>(null);

    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [appliedSearch, setAppliedSearch] = useState('');
    const [appliedStatus, setAppliedStatus] = useState('');
    const [pageSize, setPageSize] = useState<number>(DEFAULT_PAGE_SIZE);

    const [exporting, setExporting] = useState(false);

    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
    const [communication, setCommunication] = useState<{
        kind: CommunicationKind;
        scope: CommunicationScope;
        guests: Guest[];
    } | null>(null);
    const selectAllRef = useRef<HTMLInputElement>(null);

    const [formOpen, setFormOpen] = useState(false);
    const [form, setForm] = useState<GuestForm>(emptyForm);
    const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);

    const [detailsGuest, setDetailsGuest] = useState<Guest | null>(null);
    const [linkGuest, setLinkGuest] = useState<Guest | null>(null);
    const [credentialGuest, setCredentialGuest] = useState<Guest | null>(null);
    const [deleteTarget, setDeleteTarget] = useState<Guest | null>(null);
    const [deleting, setDeleting] = useState(false);

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

    const beginRequest = useRequestGuard();

    const fetchGuests = useCallback(
        async (page: number, s: string, st: string, size: number) => {
            const { signal, isCurrent } = beginRequest();
            setLoading(true);
            setError(null);
            try {
                const { data } = await api.get<GuestPage>(`/api/v1/events/${eventId}/guests`, {
                    params: { page, search: s, status: st, per_page: size },
                    signal,
                });
                // Ignore a response a newer request has superseded.
                if (!isCurrent()) return;
                setGuests(data.data);
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
        [beginRequest, eventId],
    );

    useEffect(() => {
        void fetchGuests(1, '', '', DEFAULT_PAGE_SIZE);
    }, [fetchGuests]);

    const counts = pagination?.counts;
    const total = counts?.total ?? pagination?.total ?? 0;
    const hasFilters = appliedSearch !== '' || appliedStatus !== '';
    const currentPage = pagination?.current_page ?? 1;

    const refresh = () => fetchGuests(currentPage, appliedSearch, appliedStatus, pageSize);

    const applyFilters = () => {
        clearSelection();
        const term = search.trim();
        setAppliedSearch(term);
        setAppliedStatus(status);
        void fetchGuests(1, term, status, pageSize);
    };

    const clearFilters = () => {
        clearSelection();
        setSearch('');
        setStatus('');
        setAppliedSearch('');
        setAppliedStatus('');
        void fetchGuests(1, '', '', pageSize);
    };

    const changePageSize = (size: number) => {
        clearSelection();
        setPageSize(size);
        void fetchGuests(1, appliedSearch, appliedStatus, size);
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

    // ---- Selection and communication ----
    const clearSelection = () => setSelectedIds(new Set());

    const toggleGuest = (guestId: number) => {
        setSelectedIds((current) => {
            const next = new Set(current);
            if (next.has(guestId)) next.delete(guestId);
            else next.add(guestId);
            return next;
        });
    };

    const selectedGuests = guests.filter((guest) => selectedIds.has(guest.id));
    const pageGuestIds = guests.map((guest) => guest.id);
    const allPageSelected = pageGuestIds.length > 0 && pageGuestIds.every((id) => selectedIds.has(id));
    const somePageSelected = pageGuestIds.some((id) => selectedIds.has(id));

    useEffect(() => {
        if (selectAllRef.current) {
            selectAllRef.current.indeterminate = somePageSelected && !allPageSelected;
        }
    }, [somePageSelected, allPageSelected]);

    const togglePage = () => {
        setSelectedIds((current) => {
            const next = new Set(current);
            if (allPageSelected) {
                pageGuestIds.forEach((id) => next.delete(id));
            } else {
                pageGuestIds.forEach((id) => next.add(id));
            }
            return next;
        });
    };

    const openCommunication = (kind: CommunicationKind, scope: CommunicationScope, targets: Guest[]) => {
        setCommunication({ kind, scope, guests: targets });
    };

    const handleCommunicationCompleted = async () => {
        if (communication?.scope === 'selected') clearSelection();
        await refresh();
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
                        await fetchGuests(1, appliedSearch, appliedStatus, pageSize);
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
    const initialLoadError = !loading && error !== null && guests.length === 0;

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
                    <button
                        type="button"
                        className="btn btn-ghost"
                        onClick={() => openCommunication('reminder', 'all-reminder-eligible', [])}
                    >
                        Send reminders
                    </button>
                </div>
            </header>

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error && !initialLoadError ? <Alert kind="error">{error}</Alert> : null}

            {selectedIds.size > 0 ? (
                <div className="recipient-selection-bar" role="status" aria-live="polite">
                    <div className="recipient-selection-copy">
                        <strong>{selectedIds.size} selected</strong>
                        <span>
                            {allPageSelected
                                ? `All ${guests.length} guests on this page are selected.`
                                : 'Only guests selected on this page are included.'}
                        </span>
                        <button type="button" className="btn btn-link" onClick={clearSelection}>
                            Clear selection
                        </button>
                    </div>
                    <div className="row recipient-selection-actions">
                        <button
                            type="button"
                            className="btn btn-sm btn-ghost"
                            onClick={() => openCommunication('invitation', 'selected', selectedGuests)}
                        >
                            Send invitations
                        </button>
                        <button
                            type="button"
                            className="btn btn-sm btn-primary"
                            onClick={() => openCommunication('reminder', 'selected', selectedGuests)}
                        >
                            Send reminders
                        </button>
                    </div>
                </div>
            ) : null}

            <form className="toolbar guest-filters" role="search" aria-label="Filter guests" onSubmit={(event) => { event.preventDefault(); applyFilters(); }}>
                <div className="search-field">
                    <label className="label" htmlFor="guest-search">
                        Find a guest
                    </label>
                    <input
                        id="guest-search"
                        className="input"
                        type="search"
                        placeholder="Name or email"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
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
                <button type="submit" className="btn btn-ghost" disabled={loading}>
                    Apply filters
                </button>
                {hasFilters ? (
                    <button type="button" className="btn btn-link" onClick={clearFilters} disabled={loading}>
                        Clear filters
                    </button>
                ) : null}
            </form>

            <p className="muted guest-batch-hint">
                Select guests on this page with the checkboxes to send a batch invitation or reminder.
            </p>

            {loading ? (
                <div className="table-wrap" aria-label="Loading guests" aria-busy="true">
                    <table>
                        <thead>
                            <tr>
                                <th className="select-cell" aria-hidden="true" />
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
                                    <td className="select-cell"><span className="skeleton" style={{ width: 16, height: 16 }} /></td>
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
            ) : initialLoadError ? (
                <section className="page-state page-state-error" aria-label="Guests could not be loaded">
                    <Alert kind="error">{error}</Alert>
                    <button type="button" className="btn btn-ghost" onClick={refresh}>
                        Try again
                    </button>
                </section>
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
                <div className="table-wrap guest-table-wrap">
                    <table>
                        <caption className="visually-hidden">Guests, RSVP status, and invitation actions for this event</caption>
                        <thead>
                            <tr>
                                <th scope="col" className="select-cell">
                                    <input
                                        ref={selectAllRef}
                                        type="checkbox"
                                        className="guest-select-checkbox"
                                        aria-label="Select all guests on this page"
                                        checked={allPageSelected}
                                        onChange={togglePage}
                                    />
                                </th>
                                <th scope="col">Guest</th>
                                <th scope="col" className="col-hide-sm">Email</th>
                                <th scope="col" className="col-hide-sm">Phone</th>
                                <th scope="col">RSVP</th>
                                <th scope="col" className="col-hide-sm">Attending</th>
                                <th scope="col" className="col-hide-sm">Provider accepted</th>
                                <th scope="col" className="table-actions-heading">
                                    <span className="guest-actions-label">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {guests.map((g) => {
                                const canRemind = g.status === 'pending' && !g.reminded_at;
                                return (
                                    <tr key={g.id} className={selectedIds.has(g.id) ? 'is-selected' : undefined}>
                                        <td className="select-cell">
                                            <input
                                                type="checkbox"
                                                className="guest-select-checkbox"
                                                aria-label={`Select ${g.name}`}
                                                checked={selectedIds.has(g.id)}
                                                onChange={() => toggleGuest(g.id)}
                                            />
                                        </td>
                                        <td className="cell-strong guest-name-cell">
                                            <span>{g.name}</span>
                                            {g.reminded_at ? (
                                                <span className="row-tag" title={`Reminded ${formatWhen(g.reminded_at)}`}>
                                                    reminded
                                                </span>
                                            ) : null}
                                            <span className="guest-contact-summary">
                                                <span>{g.email || g.phone || 'No contact detail'}</span>
                                                {g.status === 'accepted' ? (
                                                    <span>{g.rsvp?.attending_count ?? 1} attending</span>
                                                ) : null}
                                            </span>
                                        </td>
                                        <td className="muted col-hide-sm contact-value">{g.email || '—'}</td>
                                        <td className="muted col-hide-sm contact-value">{g.phone || '—'}</td>
                                        <td>
                                            <StatusBadge status={g.status} />
                                        </td>
                                        <td className="muted col-hide-sm">
                                            {g.status === 'accepted' ? (g.rsvp?.attending_count ?? 1) : '—'}
                                        </td>
                                        <td className="muted col-hide-sm">
                                            {g.sent_at ? new Date(g.sent_at).toLocaleDateString() : 'Not accepted'}
                                        </td>
                                        <td className="table-actions-cell">
                                            <ActionMenu
                                                label={`Actions for ${g.name}`}
                                                items={[
                                                    { label: 'View details', onSelect: () => setDetailsGuest(g) },
                                                    { label: 'Edit guest', onSelect: () => openEdit(g) },
                                                    { label: 'Invitation link', onSelect: () => setLinkGuest(g) },
                                                    ...(checkInEnabled
                                                        ? [
                                                              {
                                                                  label: 'Entry credential',
                                                                  onSelect: () => setCredentialGuest(g),
                                                              },
                                                          ]
                                                        : []),
                                                    {
                                                        label: 'Resend invitation',
                                                        onSelect: () => openCommunication('invitation', 'individual', [g]),
                                                    },
                                                    ...(canRemind
                                                        ? [
                                                              {
                                                                  label: 'Send reminder',
                                                                  onSelect: () => openCommunication('reminder', 'individual', [g]),
                                                              },
                                                          ]
                                                        : []),
                                                    { label: 'Delete guest', onSelect: () => setDeleteTarget(g), danger: true },
                                                ]}
                                            />
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            {pagination && total > 0 ? (
                <nav className="row between pagination" aria-label="Guests pagination">
                    <div className="row" style={{ gap: '0.75rem', alignItems: 'center' }}>
                        <span className="muted">
                            Page {pagination.current_page} of {pagination.last_page} · {total} guest
                            {total === 1 ? '' : 's'}
                        </span>
                        <label
                            className="muted small"
                            htmlFor="guest-page-size"
                            style={{ display: 'inline-flex', gap: '0.35rem', alignItems: 'center' }}
                        >
                            Rows
                            <select
                                id="guest-page-size"
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
                                disabled={!pagination.prev_page_url || loading}
                            onClick={() => {
                                clearSelection();
                                void fetchGuests(pagination.current_page - 1, appliedSearch, appliedStatus, pageSize);
                            }}
                        >
                            Previous
                        </button>
                        <button
                            type="button"
                            className="btn btn-sm btn-ghost"
                                disabled={!pagination.next_page_url || loading}
                            onClick={() => {
                                clearSelection();
                                void fetchGuests(pagination.current_page + 1, appliedSearch, appliedStatus, pageSize);
                            }}
                        >
                            Next
                        </button>
                    </div>
                </nav>
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
                    <dl className="details-list guest-detail-list">
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
                        <div><dt>Provider accepted</dt><dd>{detailsGuest.sent_at ? formatWhen(detailsGuest.sent_at) : 'Not accepted'}</dd></div>
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

            {/* Entry credential controls */}
            <CredentialModal
                eventId={eventId}
                guest={credentialGuest}
                onClose={() => setCredentialGuest(null)}
                onNotify={notify}
            />

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

            <CommunicationDialog
                open={communication !== null}
                eventId={eventId}
                kind={communication?.kind ?? 'invitation'}
                scope={communication?.scope ?? 'individual'}
                guests={communication?.guests ?? []}
                onClose={() => setCommunication(null)}
                onCompleted={() => void handleCommunicationCompleted()}
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
                        <div className="invite-link-layout">
                            <div className="invite-link-main">
                                <p className="invite-link-intro">
                                    Private RSVP link for <strong>{guest.name}</strong>. It stays valid until the event starts.
                                </p>
                                <p className="form-help invite-link-privacy">
                                    Anyone with this link can open this guest’s invitation and update their RSVP. Share it only with the guest.
                                </p>
                                <div className="invite-link-control">
                                    <input
                                        ref={inputRef}
                                        className="input invite-link-input"
                                        readOnly
                                        value={inviteUrl}
                                        onFocus={(e) => e.currentTarget.select()}
                                        aria-label="Invitation link"
                                        autoComplete="off"
                                        spellCheck={false}
                                    />
                                    <button type="button" className="btn btn-primary" onClick={copy}>
                                        {copyState === 'copied' ? 'Copied' : 'Copy'}
                                    </button>
                                </div>
                                {copyState === 'copied' ? (
                                    <p className="invite-link-copy-status" role="status" aria-live="polite">
                                        Invitation link copied.
                                    </p>
                                ) : null}
                                {copyState === 'error' ? (
                                    <Alert kind="error">
                                        Could not copy automatically — select the link and copy it manually.
                                    </Alert>
                                ) : null}
                                <a
                                    className="btn btn-ghost invite-link-open"
                                    href={inviteUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Open invitation
                                </a>
                            </div>
                            <div className="invite-link-qr" role="group" aria-label={`QR code for ${guest.name}’s invitation`}>
                                {qrFailed ? (
                                    <Alert kind="error">The QR code could not be loaded.</Alert>
                                ) : (
                                    <img
                                        src={`/invite/${guest.invite_token}/qr`}
                                        alt={`QR code for ${guest.name}'s invitation`}
                                        width={200}
                                        height={200}
                                        onError={() => setQrFailed(true)}
                                    />
                                )}
                            </div>
                        </div>
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

/* ---- Entry credential controls ---- */

type CredentialAction = 'reveal' | 'resend' | 'reissue' | 'revoke';
type CredentialBusy = CredentialAction | 'issue';

const CREDENTIAL_REASON_LABELS: Record<string, string> = {
    valid: 'Valid for entry',
    not_active: 'No active credential',
    revoked: 'Revoked',
    expired: 'Expired',
    unavailable: 'Unavailable',
    not_yet: 'Not valid yet',
};

function credentialStatusLabel(credential: InvitationCredential | null): string {
    if (!credential || credential.status === 'none') return 'No credential issued';
    switch (credential.status) {
        case 'active':
            return 'Active';
        case 'issued':
            return 'Issued (not yet activated)';
        case 'revoked':
            return 'Revoked';
        case 'expired':
            return 'Expired';
        default:
            return String(credential.status);
    }
}

function credentialErrorMessage(err: unknown, fallback: string): string {
    const status = (err as { response?: { status?: number } })?.response?.status;
    if (status === 401) return 'Your session has expired. Please sign in again.';
    if (status === 403) return 'You do not have permission to manage credentials for this event.';
    if (status === 404) return 'There is no active credential to reveal for this guest.';
    if (status === 422) {
        const errors = validationErrors(err);
        const first = Object.values(errors)[0];
        if (first && first[0]) return first[0];
        return apiErrorMessage(err) || fallback;
    }
    return apiErrorMessage(err) || fallback;
}

function credentialConfirmCopy(
    action: CredentialAction,
    name: string,
): { title: string; message: string; confirmLabel: string; danger: boolean } {
    switch (action) {
        case 'reveal':
            return {
                title: 'Reveal entry code',
                message: `Reveal the current entry code for ${name}? It will be shown on screen and this reveal is audited.`,
                confirmLabel: 'Reveal code',
                danger: false,
            };
        case 'resend':
            return {
                title: 'Resend entry credential',
                message: `Queue another delivery of the current entry credential to ${name}? The existing code keeps working.`,
                confirmLabel: 'Resend',
                danger: false,
            };
        case 'reissue':
            return {
                title: 'Reissue entry credential',
                message: `Reissue will revoke ${name}'s current code and create a new one. The old code stops working immediately.`,
                confirmLabel: 'Reissue',
                danger: true,
            };
        case 'revoke':
            return {
                title: 'Revoke entry credential',
                message: `Revoke ${name}'s entry code? They will not be able to check in until a new credential is issued.`,
                confirmLabel: 'Revoke',
                danger: true,
            };
    }
}

function CredentialModal({
    eventId,
    guest,
    onClose,
    onNotify,
}: {
    eventId: string;
    guest: Guest | null;
    onClose: () => void;
    onNotify: (message: string) => void;
}) {
    const guestId = guest?.id ?? null;
    const [credential, setCredential] = useState<InvitationCredential | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [code, setCode] = useState<string | null>(null);
    const [busy, setBusy] = useState<CredentialBusy | null>(null);
    const [pending, setPending] = useState<CredentialAction | null>(null);
    const [copyState, setCopyState] = useState<'idle' | 'copied' | 'error'>('idle');

    const load = useCallback(async () => {
        if (guestId === null) return;
        setLoading(true);
        setError(null);
        try {
            const { data } = await api.get<{ credential: InvitationCredential }>(
                `/api/v1/events/${eventId}/guests/${guestId}/credential`,
            );
            setCredential(data.credential);
        } catch (err) {
            setError(credentialErrorMessage(err, 'Could not load the credential.'));
        } finally {
            setLoading(false);
        }
    }, [eventId, guestId]);

    // Reset and load whenever a different guest is opened.
    useEffect(() => {
        setCredential(null);
        setCode(null);
        setCopyState('idle');
        setError(null);
        setPending(null);
        setBusy(null);
        if (guestId !== null) {
            void load();
        }
    }, [guestId, load]);

    const issue = async () => {
        if (guestId === null) return;
        setBusy('issue');
        setError(null);
        try {
            const { data } = await api.post<CredentialWithCode>(
                `/api/v1/events/${eventId}/guests/${guestId}/credential`,
            );
            setCredential(data.credential);
            setCode(data.code ?? null);
            setCopyState('idle');
            onNotify('Entry credential issued.');
        } catch (err) {
            setError(credentialErrorMessage(err, 'Could not issue the credential.'));
        } finally {
            setBusy(null);
        }
    };

    const runAction = async (action: CredentialAction) => {
        if (guestId === null) return;
        setPending(null);
        setBusy(action);
        setError(null);
        try {
            if (action === 'resend') {
                await api.post<CredentialQueuedResponse>(
                    `/api/v1/events/${eventId}/guests/${guestId}/credential/resend`,
                );
                onNotify('Entry credential queued for delivery.');
                return;
            }

            if (action === 'revoke') {
                const { data } = await api.post<{ credential: InvitationCredential }>(
                    `/api/v1/events/${eventId}/guests/${guestId}/credential/revoke`,
                );
                setCredential(data.credential);
                setCode(null);
                setCopyState('idle');
                onNotify('Entry credential revoked.');
                return;
            }

            const { data } = await api.post<CredentialWithCode>(
                `/api/v1/events/${eventId}/guests/${guestId}/credential/${action}`,
            );
            setCredential(data.credential);
            setCode(data.code ?? null);
            setCopyState('idle');
            onNotify(action === 'reveal' ? 'Entry code revealed.' : 'New entry credential issued.');
        } catch (err) {
            setError(credentialErrorMessage(err, 'The credential action failed.'));
        } finally {
            setBusy(null);
        }
    };

    const copyCode = async () => {
        if (!code) return;
        try {
            await navigator.clipboard.writeText(code);
            setCopyState('copied');
            onNotify('Entry code copied.');
        } catch {
            setCopyState('error');
        }
    };

    const hasCurrent = credential !== null && (credential.status === 'active' || credential.status === 'issued');
    const canResend = credential?.status === 'active';
    const confirm = pending ? credentialConfirmCopy(pending, guest?.name ?? 'this guest') : null;

    return (
        <>
            <Modal
                open={guest !== null}
                title="Entry credential"
                onClose={() => !busy && pending === null && onClose()}
                busy={busy !== null || pending !== null}
                maxWidth={520}
            >
                {guest ? (
                    <div className="stack">
                        <p className="muted" style={{ marginTop: 0 }}>
                            Event-entry credential for <strong>{guest.name}</strong>. This is separate from the RSVP
                            invitation link and is only shown when you reveal it.
                        </p>

                        {loading ? (
                            <LoadingBlock label="Loading credential…" />
                        ) : (
                            <>
                                {error ? <Alert kind="error">{error}</Alert> : null}

                                <dl className="details-list credential-detail-list">
                                    <div>
                                        <dt>Status</dt>
                                        <dd>{credentialStatusLabel(credential)}</dd>
                                    </div>
                                    <div>
                                        <dt>Usable now</dt>
                                        <dd>{credential?.valid ? 'Yes' : 'No'}</dd>
                                    </div>
                                    <div>
                                        <dt>Reason</dt>
                                        <dd>
                                            {credential
                                                ? CREDENTIAL_REASON_LABELS[String(credential.reason)] ??
                                                  String(credential.reason)
                                                : '—'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Issued</dt>
                                        <dd>{formatWhen(credential?.issued_at)}</dd>
                                    </div>
                                    <div>
                                        <dt>Activated</dt>
                                        <dd>{formatWhen(credential?.activated_at)}</dd>
                                    </div>
                                    {credential?.revoked_at ? (
                                        <div>
                                            <dt>Revoked</dt>
                                            <dd>
                                                {formatWhen(credential.revoked_at)}
                                                {credential.revoke_reason ? ` · ${credential.revoke_reason}` : ''}
                                            </dd>
                                        </div>
                                    ) : null}
                                    {credential?.replaces_id ? (
                                        <div>
                                            <dt>Replaces</dt>
                                            <dd>#{credential.replaces_id}</dd>
                                        </div>
                                    ) : null}
                                </dl>

                                {code ? (
                                    <div className="credential-code" role="status" aria-live="polite">
                                        <span className="credential-code-label">
                                            Entry code — keep private
                                        </span>
                                        <code className="credential-code-value">{code}</code>
                                        <div className="row">
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-ghost"
                                                onClick={copyCode}
                                            >
                                                {copyState === 'copied' ? 'Copied' : 'Copy code'}
                                            </button>
                                        </div>
                                        <span className="credential-warning">
                                            Share only with {guest.name}. Do not post, email or screenshot this code.
                                        </span>
                                        {copyState === 'error' ? (
                                            <span className="credential-warning">
                                                Could not copy automatically — select the code and copy it manually.
                                            </span>
                                        ) : null}
                                    </div>
                                ) : null}

                                <div className="credential-actions" role="group" aria-label="Entry credential actions">
                                    {hasCurrent ? (
                                        <>
                                            <button
                                                type="button"
                                                className="btn btn-primary"
                                                disabled={busy !== null}
                                                onClick={() => setPending('reveal')}
                                            >
                                                {busy === 'reveal' ? 'Revealing…' : 'Reveal code'}
                                            </button>
                                            <button
                                                type="button"
                                                className="btn btn-ghost"
                                                disabled={busy !== null || !canResend}
                                                title={canResend ? undefined : 'The credential is not active yet.'}
                                                onClick={() => setPending('resend')}
                                            >
                                                Resend
                                            </button>
                                            <button
                                                type="button"
                                                className="btn btn-ghost"
                                                disabled={busy !== null}
                                                onClick={() => setPending('reissue')}
                                            >
                                                Reissue
                                            </button>
                                            <button
                                                type="button"
                                                className="btn btn-danger"
                                                disabled={busy !== null}
                                                onClick={() => setPending('revoke')}
                                            >
                                                Revoke
                                            </button>
                                        </>
                                    ) : (
                                        <button
                                            type="button"
                                            className="btn btn-primary"
                                            disabled={busy !== null}
                                            onClick={issue}
                                        >
                                            {busy === 'issue' ? 'Issuing…' : 'Issue credential'}
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        className="btn btn-link"
                                        disabled={busy !== null}
                                        onClick={load}
                                    >
                                        Refresh
                                    </button>
                                </div>
                            </>
                        )}

                        <div className="modal-footer">
                            <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy !== null}>
                                Close
                            </button>
                        </div>
                    </div>
                ) : null}
            </Modal>

            <ConfirmDialog
                open={pending !== null}
                title={confirm?.title ?? ''}
                message={confirm?.message ?? ''}
                confirmLabel={confirm?.confirmLabel ?? 'Confirm'}
                danger={confirm?.danger ?? false}
                busy={busy !== null}
                onConfirm={() => pending && runAction(pending)}
                onCancel={() => setPending(null)}
            />
        </>
    );
}
