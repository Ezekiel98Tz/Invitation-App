import { Children, cloneElement, isValidElement, useId } from 'react';
import type { ReactElement, ReactNode } from 'react';

export function Spinner() {
    return <div className="spinner" aria-hidden="true" />;
}

export function LoadingBlock({ label = 'Loading…' }: { label?: string }) {
    return (
        <div className="row loading-block" role="status" aria-live="polite" aria-busy="true">
            <Spinner />
            <span className="muted">{label}</span>
        </div>
    );
}

export function Alert({ kind, children }: { kind: 'error' | 'success' | 'info'; children: ReactNode }) {
    const label = kind === 'error' ? 'Error' : kind === 'success' ? 'Success' : 'Info';

    return (
        <div
            className={`alert alert-${kind}`}
            role={kind === 'error' ? 'alert' : 'status'}
            aria-live={kind === 'error' ? 'assertive' : 'polite'}
            aria-atomic="true"
        >
            <span className="alert-label">{label}</span>
            {children}
        </div>
    );
}

function joinIds(existing: string | undefined, id: string | undefined): string | undefined {
    const ids = [existing, id].filter((value): value is string => Boolean(value));
    return ids.length ? [...new Set(ids)].join(' ') : undefined;
}

function labelNestedControls(
    node: ReactNode,
    labelId: string,
    describedBy: string | undefined,
    invalid: boolean,
): ReactNode {
    return Children.map(node, (child) => {
        if (!isValidElement<{ children?: ReactNode; 'aria-labelledby'?: string; 'aria-describedby'?: string; 'aria-invalid'?: boolean }>(child)) {
            return child;
        }

        if (typeof child.type === 'string' && ['input', 'select', 'textarea'].includes(child.type)) {
            return cloneElement(child as ReactElement<Record<string, unknown>>, {
                'aria-labelledby': joinIds(child.props['aria-labelledby'], labelId),
                'aria-describedby': joinIds(child.props['aria-describedby'], describedBy),
                'aria-invalid': invalid || child.props['aria-invalid'] || undefined,
            });
        }

        if (child.props.children) {
            return cloneElement(child, undefined, labelNestedControls(child.props.children, labelId, describedBy, invalid));
        }

        return child;
    });
}

export function Field({
    label,
    error,
    htmlFor,
    helperText,
    children,
}: {
    label: string;
    error?: string;
    helperText?: string;
    /** Optional id for an explicitly associated control. A direct input gets an id automatically. */
    htmlFor?: string;
    children: ReactNode;
}) {
    const generatedId = useId();
    const labelId = `field-label-${generatedId}`;
    const errorId = error ? `field-error-${generatedId}` : undefined;
    const helperId = helperText ? `field-help-${generatedId}` : undefined;
    const describedBy = joinIds(helperId, errorId);
    const directControl =
        isValidElement(children) &&
        typeof children.type === 'string' &&
        ['input', 'select', 'textarea'].includes(children.type)
            ? children
            : null;
    const isDirectControl = directControl !== null;
    const existingControlId = typeof directControl?.props.id === 'string' ? directControl.props.id : undefined;
    const existingDescribedBy =
        typeof directControl?.props['aria-describedby'] === 'string'
            ? directControl.props['aria-describedby']
            : undefined;
    const controlId = htmlFor ?? (isDirectControl ? existingControlId ?? `field-${generatedId}` : undefined);
    const control = directControl
        ? cloneElement(directControl as ReactElement<Record<string, unknown>>, {
              id: existingControlId ?? controlId,
              'aria-describedby': joinIds(existingDescribedBy, describedBy),
              'aria-invalid': error ? true : directControl.props['aria-invalid'] || undefined,
          })
        : labelNestedControls(children, labelId, describedBy, Boolean(error));

    return (
        <div className="field">
            {isDirectControl || htmlFor ? (
                <label className="label" htmlFor={controlId}>
                    {label}
                </label>
            ) : (
                <div className="label" id={labelId}>
                    {label}
                </div>
            )}
            {control}
            {helperText ? (
                <div className="form-help" id={helperId}>
                    {helperText}
                </div>
            ) : null}
            {error ? (
                <div className="error-text" id={errorId} role="alert" aria-live="polite">
                    {error}
                </div>
            ) : null}
        </div>
    );
}

export function StatusBadge({ status }: { status: string }) {
    const normalized = status.toLowerCase();
    const knownLabels: Record<string, string> = {
        'checked-in': 'Checked in',
        queued: 'Queued',
        processing: 'Processing',
        completed: 'Completed',
        failed: 'Failed',
        delivered: 'Delivered',
        sent: 'Provider accepted',
        simulated: 'Simulated',
        blocked: 'Blocked',
    };
    const tone = ['accepted', 'declined', 'pending', 'checked-in', 'reversed', 'queued', 'processing', 'completed', 'failed', 'delivered', 'sent', 'simulated', 'blocked'].includes(normalized)
        ? normalized
        : 'neutral';
    const label = knownLabels[normalized] ?? normalized.replace(/[_-]+/g, ' ');

    return <span className={`badge ${tone}`}>{label}</span>;
}

export function PageHeading({
    title,
    description,
    actions,
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <header className="page-head">
            <div className="page-heading-copy">
                <h1>{title}</h1>
                {description ? <p className="muted page-sub">{description}</p> : null}
            </div>
            {actions ? <div className="page-head-actions">{actions}</div> : null}
        </header>
    );
}

export function EmptyState({
    title,
    description,
    action,
}: {
    title: string;
    description: ReactNode;
    action?: ReactNode;
}) {
    const headingId = useId();

    return (
        <section className="empty-state" aria-labelledby={headingId}>
            <h2 id={headingId}>{title}</h2>
            <p className="muted">{description}</p>
            {action ? <div className="empty-state-actions">{action}</div> : null}
        </section>
    );
}
