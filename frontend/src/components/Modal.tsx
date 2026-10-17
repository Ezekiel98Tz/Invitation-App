import { useEffect, useId, useRef } from 'react';
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
    const titleId = useId();

    // Keep the latest callbacks in refs so the effect below can depend only on
    // `open`. Depending on `onClose`/`busy` would re-run it on every render
    // (each keystroke re-renders the form) and repeatedly steal focus.
    const onCloseRef = useRef(onClose);
    onCloseRef.current = onClose;
    const busyRef = useRef(busy);
    busyRef.current = busy;

    useEffect(() => {
        if (!open) return;

        const root = ref.current;
        const previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const focusableSelector =
            'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
        const focusableElements = () =>
            Array.from(root?.querySelectorAll<HTMLElement>(focusableSelector) ?? []).filter(
                (element) => element.getAttribute('aria-hidden') !== 'true' && !element.hasAttribute('hidden'),
            );

        const initialTarget = focusableElements()[0] ?? root;
        initialTarget?.focus();

        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                if (!busyRef.current) onCloseRef.current();
                return;
            }

            if (e.key !== 'Tab' || !root) return;
            const elements = focusableElements();
            if (elements.length === 0) {
                e.preventDefault();
                root.focus();
                return;
            }

            const first = elements[0];
            const last = elements[elements.length - 1];
            if (e.shiftKey && (document.activeElement === first || !root.contains(document.activeElement))) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && (document.activeElement === last || !root.contains(document.activeElement))) {
                e.preventDefault();
                first.focus();
            }
        };

        const onFocusIn = (event: FocusEvent) => {
            if (!root || root.contains(event.target as Node)) return;
            (focusableElements()[0] ?? root).focus();
        };

        document.addEventListener('keydown', onKey);
        document.addEventListener('focusin', onFocusIn);

        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('focusin', onFocusIn);
            if (previousFocus?.isConnected) previousFocus.focus();
        };
    }, [open]);

    if (!open) return null;

    return (
        <div
            className="modal-backdrop"
            onClick={(event) => {
                if (event.target === event.currentTarget && !busy) onClose();
            }}
        >
            <div
                className="modal"
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                tabIndex={-1}
                style={maxWidth ? { maxWidth } : undefined}
                ref={ref}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="modal-head">
                    <h2 id={titleId}>{title}</h2>
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
