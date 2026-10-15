import { useEffect, useRef, useState } from 'react';

export interface ActionMenuItem {
    label: string;
    onSelect: () => void;
    danger?: boolean;
}

export function ActionMenu({ items, label = 'More actions' }: { items: ActionMenuItem[]; label?: string }) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const onPointerDown = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setOpen(false);
        };
        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <div className="menu" ref={ref}>
            <button
                type="button"
                className="btn btn-ghost btn-sm menu-trigger"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label={label}
                onClick={() => setOpen((o) => !o)}
            >
                <span aria-hidden="true">⋯</span>
            </button>
            {open ? (
                <div className="menu-list" role="menu">
                    {items.map((item) => (
                        <button
                            key={item.label}
                            type="button"
                            role="menuitem"
                            className={`menu-item${item.danger ? ' menu-item-danger' : ''}`}
                            onClick={() => {
                                setOpen(false);
                                item.onSelect();
                            }}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>
            ) : null}
        </div>
    );
}
