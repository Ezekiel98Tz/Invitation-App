import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';

interface ModalProps {
    open: boolean;
    title: string;
    onClose: () => void;
    children: ReactNode;
    busy?: boolean;
    maxWidth?: number;
}

export function Modal({ open, title, onClose, children, busy = false, maxWidth }: ModalProps) {
    const ref = useRef<HTMLDivElement>(null);

    // Keep the latest callbacks in refs so the effect below can depend only on
    // `open`. Depending on `onClose`/`busy` would re-run it on every render
    // (each keystroke re-renders the form) and repeatedly steal focus.
    const onCloseRef = useRef(onClose);
    onCloseRef.current = onClose;
    const busyRef = useRef(busy);
    busyRef.current = busy;

    useEffect(() => {
        if (!open) return;

        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && !busyRef.current) {
                onCloseRef.current();
            }
        };
        document.addEventListener('keydown', onKey);

        const focusTimer = window.setTimeout(() => {
            const root = ref.current;
            if (!root) return;
            const target =
                root.querySelector<HTMLElement>('input:not([type=hidden]), select, textarea') ??
                root.querySelector<HTMLElement>('button');
            target?.focus();
        }, 0);

        return () => {
            document.removeEventListener('keydown', onKey);
            window.clearTimeout(focusTimer);
        };
    }, [open]);

    if (!open) return null;

    return (
        <div className="modal-backdrop" onClick={() => !busy && onClose()}>
            <div
                className="modal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="app-modal-title"
                style={maxWidth ? { maxWidth } : undefined}
                ref={ref}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="modal-head">
                    <h2 id="app-modal-title">{title}</h2>
                    <button
                        type="button"
                        className="btn btn-link"
                        onClick={onClose}
                        disabled={busy}
                        aria-label="Close dialog"
                    >
                        Close
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}
