import type { PledgeDerivedStatus } from '../lib/types';

/**
 * Shared pledge presentation bits. Every label here describes *reported*
 * information only — a fulfillment is what a client reported, never money that
 * Invitation Studio collected or verified. Pledge status is never presented as
 * gating admission.
 */

const DERIVED_LABELS: Record<PledgeDerivedStatus, string> = {
    open: 'Open',
    partially_fulfilled: 'Partially fulfilled',
    fulfilled: 'Fulfilled',
    over_fulfilled: 'Over-fulfilled',
    cancelled: 'Cancelled',
};

const DERIVED_CLASSES: Record<PledgeDerivedStatus, string> = {
    open: 'pledge-open',
    partially_fulfilled: 'pledge-partial',
    fulfilled: 'pledge-fulfilled',
    over_fulfilled: 'pledge-over',
    cancelled: 'pledge-cancelled',
};

export function derivedStatusLabel(status: string): string {
    return DERIVED_LABELS[status as PledgeDerivedStatus] ?? status;
}

/** Derived lifecycle badge: open / partially fulfilled / fulfilled / over / cancelled. */
export function PledgeStatusBadge({ status }: { status: string }) {
    const cls = DERIVED_CLASSES[status as PledgeDerivedStatus] ?? '';
    return <span className={`badge ${cls}`.trim()}>{derivedStatusLabel(status)}</span>;
}

/** Stored status badge (active / cancelled) for audit-oriented views. */
export function PledgeStoredStatusBadge({ status }: { status: string }) {
    const active = status !== 'cancelled';
    return (
        <span className={`badge ${active ? 'pledge-active' : 'pledge-cancelled'}`}>
            {active ? 'Active' : 'Cancelled'}
        </span>
    );
}

/**
 * A single fulfillment entry is either a reported (recorded) entry or a
 * compensating reversal entry.
 */
export function FulfillmentStatusBadge({ isReversal, status }: { isReversal: boolean; status: string }) {
    if (isReversal || status === 'reversed') {
        return <span className="badge pledge-cancelled">Reversal</span>;
    }
    return <span className="badge pledge-recorded">Reported</span>;
}

const METHOD_LABELS: Record<string, string> = {
    cash: 'Cash',
    bank_transfer: 'Bank transfer',
    mobile_money: 'Mobile money',
    cheque: 'Cheque',
    other: 'Other',
    unspecified: 'Unspecified',
};

export function methodLabel(method: string | null | undefined): string {
    if (!method) return 'Unspecified';
    return METHOD_LABELS[method] ?? method;
}
