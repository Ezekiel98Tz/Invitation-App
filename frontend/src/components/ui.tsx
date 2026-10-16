import type { ReactNode } from 'react';

export function Spinner() {
    return <div className="spinner" aria-label="Loading" />;
}

export function LoadingBlock({ label = 'Loading…' }: { label?: string }) {
    return (
        <div className="row" style={{ padding: '1.5rem', justifyContent: 'center' }}>
            <Spinner />
            <span className="muted">{label}</span>
        </div>
    );
}

export function Alert({ kind, children }: { kind: 'error' | 'success' | 'info'; children: ReactNode }) {
    return <div className={`alert alert-${kind}`}>{children}</div>;
}

export function Field({
    label,
    error,
    htmlFor,
    children,
}: {
    label: string;
    error?: string;
    /** Optional id of the control, so the label is programmatically associated. */
    htmlFor?: string;
    children: ReactNode;
}) {
    return (
        <div className="field">
            <label className="label" htmlFor={htmlFor}>
                {label}
            </label>
            {children}
            {error ? <div className="error-text">{error}</div> : null}
        </div>
    );
}

export function StatusBadge({ status }: { status: string }) {
    return <span className={`badge ${status}`}>{status}</span>;
}
