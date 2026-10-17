import { useEffect, useId, useRef, useState } from 'react';

export interface ActionMenuItem {
    label: string;
    onSelect: () => void;
    danger?: boolean;
}

export function ActionMenu({ items, label = 'More actions' }: { items: ActionMenuItem[]; label?: string }) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    const menuId = useId();

    useEffect(() => {
        if (!open) return;
        const menuItems = () => Array.from(menuRef.current?.querySelectorAll<HTMLButtonElement>('[role="menuitem"]') ?? []);
        menuItems()[0]?.focus();

        const onPointerDown = (e: PointerEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                setOpen(false);
                triggerRef.current?.focus();
                return;
            }

            if (e.key === 'Tab') {
                setOpen(false);
                return;
            }

            const items = menuItems();
            if (items.length === 0) return;

            const currentIndex = items.indexOf(document.activeElement as HTMLButtonElement);
            let nextIndex: number | null = null;
            if (e.key === 'ArrowDown') nextIndex = (currentIndex + 1) % items.length;
            if (e.key === 'ArrowUp') nextIndex = (currentIndex - 1 + items.length) % items.length;
            if (e.key === 'Home') nextIndex = 0;
            if (e.key === 'End') nextIndex = items.length - 1;
            if (nextIndex !== null) {
                e.preventDefault();
                items[nextIndex]?.focus();
            }
        };
        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
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
                aria-controls={menuId}
                aria-label={label}
                ref={triggerRef}
                onClick={() => setOpen((o) => !o)}
            >
                <svg viewBox="0 0 20 20" width="18" height="18" fill="currentColor" aria-hidden="true" focusable="false">
                    <circle cx="4" cy="10" r="1.5" />
                    <circle cx="10" cy="10" r="1.5" />
                    <circle cx="16" cy="10" r="1.5" />
                </svg>
            </button>
            {open ? (
                <div className="menu-list" id={menuId} role="menu" ref={menuRef} aria-label={label}>
                    {items.map((item) => (
                        <button
                            key={item.label}
                            type="button"
                            role="menuitem"
                            className={`menu-item${item.danger ? ' menu-item-danger' : ''}`}
                            onClick={() => {
                                setOpen(false);
                                triggerRef.current?.focus();
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
