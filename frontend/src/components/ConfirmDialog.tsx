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
}: ConfirmDialogProps) {
    return (
        <Modal open={open} title={title} onClose={onCancel} busy={busy} maxWidth={440}>
            <div className="muted" style={{ marginTop: 0 }}>
                {message}
            </div>
            <div className="modal-footer">
                <button type="button" className="btn btn-ghost" onClick={onCancel} disabled={busy}>
                    Cancel
                </button>
                <button
                    type="button"
                    className={`btn ${danger ? 'btn-danger-solid' : 'btn-primary'}`}
                    onClick={onConfirm}
                    disabled={busy}
                >
                    {busy ? 'Working…' : confirmLabel}
                </button>
            </div>
        </Modal>
    );
}
