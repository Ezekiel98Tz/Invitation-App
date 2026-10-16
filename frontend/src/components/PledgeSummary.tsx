import type { ReactNode } from 'react';
import type { PledgeCurrencyTotal, PledgeDerivedCounts, PledgeMethodTotal } from '../lib/types';
import { formatMoney } from '../lib/money';
import { methodLabel } from './PledgeBadges';

interface Props {
    heading: string;
    totalPledges: number;
    counts: PledgeDerivedCounts;
    currencies: PledgeCurrencyTotal[];
    /** Optional per-method reported entry counts. */
    methods?: PledgeMethodTotal[];
    /** Optional footer, e.g. a link or a per-event breakdown. */
    footer?: ReactNode;
}

/**
 * Currency-aware pledge totals.
 *
 * Pledged, reported-fulfilled and outstanding figures are always shown per
 * currency — different currencies are never added into one misleading total.
 * Cancelled pledges are counted above but excluded from the money totals, which
 * matches the server aggregation.
 */
export function PledgeSummary({ heading, totalPledges, counts, currencies, methods, footer }: Props) {
    return (
        <section className="card" aria-label={heading}>
            <div className="section-head" style={{ marginBottom: '0.75rem' }}>
                <div>
                    <h3 style={{ margin: 0 }}>{heading}</h3>
                    <p className="muted small" style={{ margin: '0.15rem 0 0' }}>
                        {totalPledges === 1 ? '1 pledge recorded' : `${totalPledges} pledges recorded`}
                    </p>
                </div>
            </div>

            <div className="counts" aria-label="Pledge status counts">
                <span className="count">
                    <strong>{totalPledges}</strong> total
                </span>
                <span className="count pledge-open">
                    <strong>{counts.open}</strong> open
                </span>
                <span className="count pledge-partial">
                    <strong>{counts.partially_fulfilled}</strong> partially fulfilled
                </span>
                <span className="count pledge-fulfilled">
                    <strong>{counts.fulfilled}</strong> fulfilled
                </span>
                <span className="count pledge-over">
                    <strong>{counts.over_fulfilled}</strong> over-fulfilled
                </span>
                <span className="count pledge-cancelled">
                    <strong>{counts.cancelled}</strong> cancelled
                </span>
            </div>

            {currencies.length === 0 ? (
                <p className="muted" style={{ marginBottom: 0 }}>
                    No money figures to show. Cancelled pledges are counted above but excluded from the totals.
                </p>
            ) : (
                <div className="table-wrap" style={{ marginTop: '0.75rem' }}>
                    <table className="pledge-summary-table">
                        <caption className="visually-hidden">Reported pledge totals by currency</caption>
                        <thead>
                            <tr>
                                <th scope="col">Currency</th>
                                <th scope="col" className="num">
                                    Pledges
                                </th>
                                <th scope="col" className="num">
                                    Pledged
                                </th>
                                <th scope="col" className="num">
                                    Reported fulfilled
                                </th>
                                <th scope="col" className="num">
                                    Outstanding
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {currencies.map((total) => (
                                <tr key={total.currency}>
                                    <td className="cell-strong">{total.currency}</td>
                                    <td className="muted num">{total.pledges}</td>
                                    <td className="num">{formatMoney(total.pledged_minor, total.currency)}</td>
                                    <td className="num">{formatMoney(total.fulfilled_minor, total.currency)}</td>
                                    <td className="num cell-strong">
                                        {formatMoney(total.outstanding_minor, total.currency)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <p className="muted small" style={{ margin: '0.6rem 0 0' }}>
                Figures are reported amounts, grouped per currency and never combined. Cancelled pledges are excluded
                from the totals. Invitation Studio does not collect or verify money.
            </p>

            {methods && methods.length > 0 ? (
                <details style={{ marginTop: '0.6rem' }}>
                    <summary className="muted small" style={{ cursor: 'pointer' }}>
                        Reported entries by method
                    </summary>
                    <ul className="help-list" style={{ marginTop: '0.5rem' }}>
                        {methods.map((entry) => (
                            <li key={`${entry.method}:${entry.currency}`}>
                                {methodLabel(entry.method)} ({entry.currency}):{' '}
                                <strong>{entry.entries}</strong>{' '}
                                {entry.entries === 1 ? 'entry' : 'entries'}
                            </li>
                        ))}
                    </ul>
                    <p className="muted small" style={{ marginBottom: 0 }}>
                        Entry counts are grouped per method and currency; reported amounts per currency are in the table
                        above.
                    </p>
                </details>
            ) : null}

            {footer}
        </section>
    );
}
