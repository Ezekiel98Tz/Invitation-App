import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api, apiErrorMessage } from '../lib/api';
import type { EventItem, EventPledgeSummary, Pledge, PledgePage } from '../lib/types';
import { formatMoney } from '../lib/money';
import { isCapabilityEnabled } from '../lib/capabilities';
import { Alert, LoadingBlock } from '../components/ui';
import { ActionMenu } from '../components/ActionMenu';
import { FeatureDisabled } from '../components/FeatureDisabled';
import { PledgeFormModal } from '../components/PledgeFormModal';
import { PledgeStatusBadge } from '../components/PledgeBadges';
import { PledgeSummary } from '../components/PledgeSummary';

const METHOD_OPTIONS: { value: string; label: string }[] = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'mobile_money', label: 'Mobile money' },
    { value: 'cheque', label: 'Cheque' },
    { value: 'other', label: 'Other' },
];

function formatWhen(iso: string | null | undefined): string {
    if (!iso) return '—';
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? iso : date.toLocaleDateString();
}

function filenameFromDisposition(disposition: string | undefined, fallback: string): string {
    if (!disposition) return fallback;
    const match = /filename\*?=(?:UTF-8'')?["']?([^"';]+)/i.exec(disposition);
    return match ? decodeURIComponent(match[1]) : fallback;
}

async function describeBlobError(err: unknown, fallback: string): Promise<string> {
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

async function textFromBlob(blob: Blob): Promise<string | null> {
    try {
        const json = JSON.parse(await blob.text()) as { message?: string };
        return json?.message ?? null;
    } catch {
        return null;
    }
}

export function PledgesPage() {
    const { id } = useParams<{ id: string }>();
    const eventId = id ?? '';
    const navigate = useNavigate();

    const [event, setEvent] = useState<EventItem | null>(null);
    const [eventState, setEventState] = useState<'loading' | 'loaded' | 'failed'>('loading');
    const [summary, setSummary] = useState<EventPledgeSummary | null>(null);
    const [summaryError, setSummaryError] = useState<string | null>(null);

    const [pledges, setPledges] = useState<Pledge[]>([]);
    const [pagination, setPagination] = useState<PledgePage | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [toast, setToast] = useState<string | null>(null);

    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [method, setMethod] = useState('');
    const [appliedSearch, setAppliedSearch] = useState('');
    const [appliedStatus, setAppliedStatus] = useState('');
    const [appliedMethod, setAppliedMethod] = useState('');

    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Pledge | null>(null);
    const [exporting, setExporting] = useState(false);

    const notify = useCallback((message: string) => {
        setToast(message);
        window.setTimeout(() => setToast(null), 3500);
    }, []);

    const loadEvent = useCallback(async () => {
        try {
            const { data } = await api.get<EventItem>(`/api/v1/events/${eventId}`);
            setEvent(data);
            setEventState('loaded');
        } catch {
            // Best-effort heading and capability source only. When it fails we
            // still attempt the pledge calls so the real 403/404 is surfaced.
            setEventState('failed');
        }
    }, [eventId]);

    const loadSummary = useCallback(async () => {
        setSummaryError(null);
        try {
            const { data } = await api.get<EventPledgeSummary>(`/api/v1/events/${eventId}/pledges/summary`);
            setSummary(data);
        } catch (err) {
            setSummaryError(apiErrorMessage(err));
        }
    }, [eventId]);

    const fetchPledges = useCallback(
        async (page: number, s: string, st: string, me: string) => {
            setLoading(true);
            setError(null);
            try {
                const { data } = await api.get<PledgePage>(`/api/v1/events/${eventId}/pledges`, {
                    params: { page, search: s, status: st, method: me },
                });
                setPledges(data.data);
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
        void loadEvent();
    }, [loadEvent]);

    // Only reach the capability-gated endpoints once the event is known to
    // allow contributions; a disabled feature shows the friendly state instead
    // of a raw 403. If the event could not be read at all we still attempt the
    // calls so the backend's own 403/404 is surfaced rather than hidden.
    useEffect(() => {
        if (eventState === 'loading') return;
        if (eventState === 'loaded' && event && !isCapabilityEnabled(event, 'contributions')) return;
        void loadSummary();
        void fetchPledges(1, '', '', '');
    }, [eventState, event, loadSummary, fetchPledges]);

    const currentPage = pagination?.current_page ?? 1;
    const hasFilters = appliedSearch !== '' || appliedStatus !== '' || appliedMethod !== '';

    const refresh = useCallback(async () => {
        await Promise.all([
            fetchPledges(currentPage, appliedSearch, appliedStatus, appliedMethod),
            loadSummary(),
        ]);
    }, [fetchPledges, loadSummary, currentPage, appliedSearch, appliedStatus, appliedMethod]);

    const applyFilters = () => {
        setAppliedSearch(search);
        setAppliedStatus(status);
        setAppliedMethod(method);
        void fetchPledges(1, search, status, method);
    };

    const clearFilters = () => {
        setSearch('');
        setStatus('');
        setMethod('');
        setAppliedSearch('');
        setAppliedStatus('');
        setAppliedMethod('');
        void fetchPledges(1, '', '', '');
    };

    const openCreate = () => {
        setEditing(null);
        setFormOpen(true);
    };

    const openEdit = (pledge: Pledge) => {
        setEditing(pledge);
        setFormOpen(true);
    };

    const handleSaved = async (pledge: Pledge) => {
        setFormOpen(false);
        notify(editing ? 'Pledge updated.' : 'Pledge recorded.');
        // Keep the current page/filters; jump to page 1 only when creating.
        if (editing) {
            await refresh();
        } else {
            setAppliedSearch(search);
            setAppliedStatus(status);
            setAppliedMethod(method);
            await Promise.all([fetchPledges(1, search, status, method), loadSummary()]);
        }
    };

    const exportPledges = async () => {
        setExporting(true);
        setError(null);
        try {
            const response = await api.get(`/api/v1/events/${eventId}/pledges/export`, { responseType: 'blob' });
            const contentType = String(response.headers['content-type'] ?? '');
            const blob = response.data as Blob;

            if (blob.size === 0) throw new Error('The export returned an empty file.');
            if (!/spreadsheet|officedocument|ms-excel|octet-stream/i.test(contentType)) {
                throw new Error(
                    (await textFromBlob(blob)) ?? 'The export did not return a spreadsheet.',
                );
            }

            const filename = filenameFromDisposition(
                String(response.headers['content-disposition'] ?? ''),
                `pledges-${eventId}.xlsx`,
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
            setError(await describeBlobError(err, 'Export failed.'));
        } finally {
            setExporting(false);
        }
    };

    const defaultCurrency = summary?.currencies[0]?.currency ?? null;

    const contributionsEnabled = isCapabilityEnabled(event, 'contributions');

    if (eventState === 'loading') {
        return <LoadingBlock label="Loading pledges…" />;
    }

    if (eventState === 'loaded' && event && !contributionsEnabled) {
        return <FeatureDisabled eventId={eventId} feature="Contributions (pledges)" />;
    }

    return (
        <div className="stack">
            <div className="row between">
                <div>
                    <Link to={`/events/${eventId}`} className="muted">
                        ← Back to event
                    </Link>
                    <h1 style={{ marginTop: '0.5rem' }}>Pledges</h1>
                    <p className="muted" style={{ marginTop: 0 }}>
                        {event?.title ?? 'Event'} · reported pledges and fulfillments. Invitation Studio does not
                        collect or verify money.
                    </p>
                </div>
                <div className="row">
                    <button
                        type="button"
                        className="btn btn-ghost"
                        onClick={() => navigate(`/events/${eventId}/pledges/import`)}
                    >
                        Import pledges
                    </button>
                    <button type="button" className="btn btn-ghost" onClick={exportPledges} disabled={exporting}>
                        {exporting ? 'Exporting…' : 'Export pledges'}
                    </button>
                    <button type="button" className="btn btn-primary" onClick={openCreate}>
                        Record pledge
                    </button>
                </div>
            </div>

            {toast ? <Alert kind="success">{toast}</Alert> : null}
            {error ? <Alert kind="error">{error}</Alert> : null}

            {summaryError ? (
                <Alert kind="error">Could not load the pledge summary. {summaryError}</Alert>
            ) : summary ? (
                <PledgeSummary
                    heading="Event summary"
                    totalPledges={summary.total_pledges}
                    counts={summary.counts}
                    currencies={summary.currencies}
                    methods={summary.by_method}
                />
            ) : null}

            <div className="card">
                <div className="toolbar">
                    <div className="search-field">
                        <label className="label" htmlFor="pledge-search">
                            Search
                        </label>
                        <input
                            id="pledge-search"
                            className="input"
                            type="search"
                            placeholder="Pledger name, email, or phone"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                        />
                    </div>
                    <div className="filter-field">
                        <label className="label" htmlFor="pledge-status">
                            Status
                        </label>
                        <select
                            id="pledge-status"
                            className="select"
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                        >
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div className="filter-field">
                        <label className="label" htmlFor="pledge-method">
                            Reported method
                        </label>
                        <select
                            id="pledge-method"
                            className="select"
                            value={method}
                            onChange={(e) => setMethod(e.target.value)}
                        >
                            <option value="">Any method</option>
                            {METHOD_OPTIONS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
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
                                    <th>Pledger</th>
                                    <th>Pledged</th>
                                    <th className="col-hide-sm">Reported fulfilled</th>
                                    <th>Outstanding</th>
                                    <th>Status</th>
                                    <th className="col-hide-sm">Created</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {[0, 1, 2, 3].map((row) => (
                                    <tr key={row}>
                                        <td>
                                            <span className="skeleton" style={{ width: '60%' }} />
                                        </td>
                                        <td>
                                            <span className="skeleton" style={{ width: 80 }} />
                                        </td>
                                        <td className="col-hide-sm">
                                            <span className="skeleton" style={{ width: 80 }} />
                                        </td>
                                        <td>
                                            <span className="skeleton" style={{ width: 80 }} />
                                        </td>
                                        <td>
                                            <span className="skeleton" style={{ width: 72 }} />
                                        </td>
                                        <td className="col-hide-sm">
                                            <span className="skeleton" style={{ width: 64 }} />
                                        </td>
                                        <td />
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : pledges.length === 0 ? (
                    <div className="empty-state">
                        {hasFilters ? (
                            <>
                                <h3>No pledges match these filters</h3>
                                <p className="muted">Try a different search, status, or method, or clear the filters.</p>
                                <button type="button" className="btn btn-ghost" onClick={clearFilters}>
                                    Clear filters
                                </button>
                            </>
                        ) : (
                            <>
                                <h3>No pledges yet</h3>
                                <p className="muted">
                                    Record a pledge to track what a client reports they will give. Fulfillments are
                                    recorded later, as they are reported.
                                </p>
                                <button type="button" className="btn btn-primary" onClick={openCreate}>
                                    Record pledge
                                </button>
                            </>
                        )}
                    </div>
                ) : (
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Pledger</th>
                                    <th>Pledged</th>
                                    <th className="col-hide-sm">Reported fulfilled</th>
                                    <th>Outstanding</th>
                                    <th>Status</th>
                                    <th className="col-hide-sm">Created</th>
                                    <th style={{ textAlign: 'right' }}>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {pledges.map((pledge) => (
                                    <tr key={pledge.id}>
                                        <td className="cell-strong">
                                            <Link to={`/events/${eventId}/pledges/${pledge.id}`}>
                                                {pledge.pledger_name ?? 'Unnamed pledge'}
                                            </Link>
                                            {pledge.pledger_email || pledge.pledger_phone ? (
                                                <div className="muted small">
                                                    {[pledge.pledger_email, pledge.pledger_phone]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </div>
                                            ) : null}
                                            <span className="row-tag" title="Where this pledge came from">
                                                {pledge.contact_id ? (
                                                    <Link to={`/contacts/${pledge.contact_id}`} className="muted">
                                                        contact #{pledge.contact_id}
                                                    </Link>
                                                ) : pledge.guest_id ? (
                                                    <>guest #{pledge.guest_id}</>
                                                ) : null}
                                                {pledge.contact_id || pledge.guest_id ? ' · ' : ''}
                                                {pledge.source}
                                            </span>
                                        </td>
                                        <td>{formatMoney(pledge.pledged_amount_minor, pledge.currency)}</td>
                                        <td className="col-hide-sm">
                                            {formatMoney(pledge.fulfilled_minor, pledge.currency)}
                                        </td>
                                        <td className="cell-strong">
                                            {formatMoney(pledge.outstanding_minor, pledge.currency)}
                                        </td>
                                        <td>
                                            <PledgeStatusBadge status={pledge.derived_status} />
                                        </td>
                                        <td className="muted col-hide-sm">{formatWhen(pledge.created_at)}</td>
                                        <td style={{ textAlign: 'right' }}>
                                            <ActionMenu
                                                label={`Actions for ${pledge.pledger_name ?? `pledge ${pledge.id}`}`}
                                                items={[
                                                    {
                                                        label: 'View details',
                                                        onSelect: () =>
                                                            navigate(`/events/${eventId}/pledges/${pledge.id}`),
                                                    },
                                                    { label: 'Edit pledge', onSelect: () => openEdit(pledge) },
                                                ]}
                                            />
                                        </td>
                                    </tr>
                                ))}
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
                                onClick={() =>
                                    fetchPledges(pagination.current_page - 1, appliedSearch, appliedStatus, appliedMethod)
                                }
                            >
                                Previous
                            </button>
                            <button
                                type="button"
                                className="btn btn-sm btn-ghost"
                                disabled={!pagination.next_page_url}
                                onClick={() =>
                                    fetchPledges(pagination.current_page + 1, appliedSearch, appliedStatus, appliedMethod)
                                }
                            >
                                Next
                            </button>
                        </div>
                    </div>
                ) : null}
            </div>

            <PledgeFormModal
                open={formOpen}
                eventId={eventId}
                pledge={editing}
                defaultCurrency={defaultCurrency}
                onClose={() => setFormOpen(false)}
                onSaved={handleSaved}
                onError={setError}
            />
        </div>
    );
}
