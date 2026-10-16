import { useCallback, useEffect, useId, useRef, useState } from 'react';

export interface PickerOption {
    id: number;
    label: string;
    hint?: string | null;
}

interface Props {
    label: string;
    helpText?: string;
    placeholder?: string;
    /** Currently selected option, or null when nothing is linked. */
    value: PickerOption | null;
    onChange: (option: PickerOption | null) => void;
    /** Resolve candidate options for a search term (empty string = first page). */
    search: (term: string) => Promise<PickerOption[]>;
    /** Shown when a search returns no candidates. */
    emptyText: string;
}

/**
 * Accessible search-and-select picker used to link a pledge to a guest or a
 * contact. The results list is a keyboard-navigable listbox: type to search,
 * Arrow keys to move, Enter to select, Escape to dismiss.
 */
export function EntityPicker({ label, helpText, placeholder, value, onChange, search, emptyText }: Props) {
    const reactId = useId();
    const inputId = `${reactId}-input`;
    const listboxId = `${reactId}-listbox`;
    const optionId = (index: number) => `${reactId}-opt-${index}`;

    const [query, setQuery] = useState('');
    const [options, setOptions] = useState<PickerOption[]>([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [activeIndex, setActiveIndex] = useState(-1);

    const requestRef = useRef(0);
    const rootRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const runSearch = useCallback(
        async (term: string) => {
            const token = ++requestRef.current;
            setLoading(true);
            setError(null);
            try {
                const results = await search(term);
                if (token !== requestRef.current) return;
                setOptions(results);
                setActiveIndex(results.length > 0 ? 0 : -1);
                setOpen(true);
            } catch {
                if (token !== requestRef.current) return;
                setError('Could not load matching records.');
                setOptions([]);
                setOpen(true);
            } finally {
                if (token === requestRef.current) setLoading(false);
            }
        },
        [search],
    );

    // Debounced search while the field is open.
    useEffect(() => {
        if (!open) return;
        const term = query.trim();
        const timer = window.setTimeout(() => {
            void runSearch(term);
        }, term === '' ? 0 : 280);
        return () => window.clearTimeout(timer);
    }, [query, open, runSearch]);

    // Dismiss the results when clicking outside the picker.
    useEffect(() => {
        if (!open) return;
        const onDown = (event: MouseEvent) => {
            if (rootRef.current && !rootRef.current.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onDown);
        return () => document.removeEventListener('mousedown', onDown);
    }, [open]);

    const select = (option: PickerOption) => {
        onChange(option);
        setQuery('');
        setOptions([]);
        setOpen(false);
    };

    const clear = () => {
        onChange(null);
        setQuery('');
        setOptions([]);
        setOpen(false);
        inputRef.current?.focus();
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setOpen(true);
            setActiveIndex((index) => Math.min(index + 1, options.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex((index) => Math.max(index - 1, 0));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (open && activeIndex >= 0 && options[activeIndex]) {
                select(options[activeIndex]);
            } else {
                void runSearch(query.trim());
            }
        } else if (event.key === 'Escape') {
            setOpen(false);
        }
    };

    return (
        <div className="picker" ref={rootRef}>
            <label className="label" htmlFor={inputId}>
                {label}
            </label>
            <input
                ref={inputRef}
                id={inputId}
                className="input"
                type="search"
                role="combobox"
                aria-expanded={open}
                aria-controls={listboxId}
                aria-autocomplete="list"
                aria-activedescendant={open && activeIndex >= 0 ? optionId(activeIndex) : undefined}
                placeholder={placeholder}
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                onFocus={() => setOpen(true)}
                onKeyDown={onKeyDown}
                autoComplete="off"
            />

            {value ? (
                <div className="picker-selected">
                    <span>
                        <span className="cell-strong">{value.label}</span>
                        {value.hint ? <span className="muted small"> · {value.hint}</span> : null}
                    </span>
                    <button type="button" className="btn btn-link" onClick={clear}>
                        Clear
                    </button>
                </div>
            ) : null}

            {open ? (
                <div className="picker-list" role="listbox" id={listboxId} aria-label={`${label} results`}>
                    {loading ? (
                        <div className="picker-empty muted small">Searching…</div>
                    ) : error ? (
                        <div className="picker-empty error-text">{error}</div>
                    ) : options.length === 0 ? (
                        <div className="picker-empty muted small">{emptyText}</div>
                    ) : (
                        options.map((option, index) => (
                            <button
                                key={option.id}
                                type="button"
                                role="option"
                                id={optionId(index)}
                                aria-selected={index === activeIndex}
                                className={`picker-option${index === activeIndex ? ' active' : ''}`}
                                onMouseEnter={() => setActiveIndex(index)}
                                onClick={() => select(option)}
                            >
                                <span className="cell-strong">{option.label}</span>
                                {option.hint ? <span className="muted small">{option.hint}</span> : null}
                            </button>
                        ))
                    )}
                </div>
            ) : null}

            {helpText ? <p className="form-help">{helpText}</p> : null}
        </div>
    );
}
