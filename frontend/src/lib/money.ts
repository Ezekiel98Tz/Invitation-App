/**
 * Money formatting for pledges.
 *
 * Pledges store integer amounts in minor units together with an ISO-4217
 * currency code. We render the major-unit value using the currency's own
 * fraction digits, so a JPY pledge is not shown with two decimals. Nothing here
 * moves, holds or verifies money — it only formats what was reported.
 */

const currencyFormatters = new Map<string, Intl.NumberFormat>();

/**
 * The number of minor units in one major unit for a currency (for example 2 for
 * USD, 0 for JPY). Unknown or blank codes fall back to 2.
 */
export function currencyMinorDigits(currency: string | null | undefined): number {
    const code = (currency ?? '').trim().toUpperCase();
    if (code === '') return 2;
    try {
        const digits = new Intl.NumberFormat('en', {
            style: 'currency',
            currency: code,
        }).resolvedOptions().maximumFractionDigits;
        return typeof digits === 'number' ? digits : 2;
    } catch {
        return 2;
    }
}

/** Format an integer minor-unit amount with an explicit currency code. */
export function formatMoney(amountMinor: number, currency: string | null | undefined): string {
    const code = (currency ?? '').trim().toUpperCase();
    const minor = Number.isFinite(amountMinor) ? Math.trunc(amountMinor) : 0;

    if (code !== '') {
        let formatter = currencyFormatters.get(code);
        if (!formatter) {
            try {
                formatter = new Intl.NumberFormat('en', { style: 'currency', currency: code });
                currencyFormatters.set(code, formatter);
            } catch {
                formatter = undefined;
            }
        }
        if (formatter) {
            const digits = formatter.resolvedOptions().maximumFractionDigits ?? 2;
            return formatter.format(minor / 10 ** digits);
        }
    }

    // Unknown or blank code: show the number and the code explicitly rather
    // than a currency symbol that could be misread.
    const digits = currencyMinorDigits(code);
    const decimal = new Intl.NumberFormat('en', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(minor / 10 ** digits);
    return code === '' ? decimal : `${code} ${decimal}`;
}

/**
 * Convert a major-unit value (what an operator types) into integer minor units.
 * Returns `null` when the value is not a finite, non-negative number.
 */
export function majorToMinor(major: number, currency: string | null | undefined): number | null {
    if (!Number.isFinite(major) || major < 0) return null;
    return Math.round(major * 10 ** currencyMinorDigits(currency));
}

/**
 * Render a minor-unit amount as a plain major-unit string for an `<input>`
 * (no grouping separators, exact fraction digits).
 */
export function minorToMajorInput(amountMinor: number, currency: string | null | undefined): string {
    const digits = currencyMinorDigits(currency);
    const minor = Number.isFinite(amountMinor) ? Math.trunc(amountMinor) : 0;
    return (minor / 10 ** digits).toFixed(digits);
}
