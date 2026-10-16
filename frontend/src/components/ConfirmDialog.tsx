import type { ReactNode } from 'react';
import { Modal } from './Modal';

interface ConfirmDialogProps {
    open: boolean;
    title: string;
    message: ReactNode;
    confirmLabel: string;
    onConfirm: () => void;
    onCancel: () => void;
    busy?: boolean;
    danger?: boolean;
    /** Optional extra content (for example a required reason field). */
    children?: ReactNode;
    /** Disable the confirm button (for example until a required field is filled). */
    confirmDisabled?: boolean;
}

export function ConfirmDialog({
    open,
    title,
    message,
    confirmLabel,
    onConfirm,
    onCancel,
    busy = false,
    danger = false,
    children,
    confirmDisabled = false,
}: ConfirmDialogProps) {
    return (
        <Modal open={open} title={title} onClose={onCancel} busy={busy} maxWidth={440}>
            <div className="muted" style={{ marginTop: 0 }}>
                {message}
            </div>
            {children}
            <div className="modal-footer">
                <button type="button" className="btn btn-ghost" onClick={onCancel} disabled={busy}>
                    Cancel
                </button>
                <button
                    type="button"
                    className={`btn ${danger ? 'btn-danger-solid' : 'btn-primary'}`}
                    onClick={onConfirm}
                    disabled={busy || confirmDisabled}
                >
                    {busy ? 'Working…' : confirmLabel}
                </button>
            </div>
        </Modal>
    );
}
