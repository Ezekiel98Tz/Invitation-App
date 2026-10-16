import { useCallback, useEffect, useState } from 'react';
import { api, apiErrorMessage, validationErrors } from '../lib/api';
import type { ContactPage, GuestPage, Pledge } from '../lib/types';
import { majorToMinor, minorToMajorInput } from '../lib/money';
import { Field } from './ui';
import { Modal } from './Modal';
import { EntityPicker } from './EntityPicker';
import type { PickerOption } from './EntityPicker';

const CURRENCY_SUGGESTIONS = ['USD', 'EUR', 'GBP', 'TZS', 'KES', 'UGX', 'ZAR'];

interface FormState {
    guest: PickerOption | null;
    contact: PickerOption | null;
    name: string;
    email: string;
    phone: string;
    currency: string;
    amount: string;
    note: string;
}

function emptyForm(currency: string): FormState {
    return {
        guest: null,
        contact: null,
        name: '',
        email: '',
        phone: '',
        currency,
        amount: '',
        note: '',
    };
}

function fromPledge(pledge: Pledge): FormState {
    return {
        guest: pledge.guest_id != null ? { id: pledge.guest_id, label: `Guest #${pledge.guest_id}` } : null,
        contact: pledge.contact_id != null ? { id: pledge.contact_id, label: `Contact #${pledge.contact_id}` } : null,
        name: pledge.pledger_name ?? '',
        email: pledge.pledger_email ?? '',
        phone: pledge.pledger_phone ?? '',
        currency: pledge.currency,
        amount: minorToMajorInput(pledge.pledged_amount_minor, pledge.currency),
        note: pledge.note ?? '',
    };
}

interface Props {
    open: boolean;
    eventId: string;
    /** When set, the modal edits this pledge; otherwise it records a new one. */
    pledge?: Pledge | null;
    /** Prefilled currency for a new pledge (for example the event's existing code). */
    defaultCurrency?: string | null;
    onClose: () => void;
    onSaved: (pledge: Pledge) => void;
    onError: (message: string) => void;
}

/**
 * Manual create/edit of a pledge. Only backend-permitted fields are sent, and
 * nothing here implies money was collected — a pledge is a reported intention
 * and a fulfillment is a reported event.
 */
export function PledgeFormModal({
    open,
    eventId,
    pledge = null,
    defaultCurrency = null,
    onClose,
    onSaved,
    onError,
}: Props) {
    const isEdit = pledge !== null;
    const [form, setForm] = useState<FormState>(() => emptyForm(defaultCurrency ?? ''));
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);

    // Reset the form each time the modal opens for a given pledge.
    useEffect(() => {
        if (!open) return;
        setForm(pledge ? fromPledge(pledge) : emptyForm(defaultCurrency ?? ''));
        setErrors({});
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, pledge, defaultCurrency]);

    const hasFulfillments = Boolean(
        pledge && ((pledge.fulfillments_count ?? 0) > 0 || (pledge.fulfillments?.length ?? 0) > 0),
    );
    const nameRequired = form.guest === null && form.contact === null;

    const searchGuests = useCallback(
        async (term: string): Promise<PickerOption[]> => {
            const { data } = await api.get<GuestPage>(`/api/v1/events/${eventId}/guests`, {
                params: { search: term },
            });
            return data.data.map((guest) => ({
                id: guest.id,
                label: guest.name,
                hint: guest.email ?? guest.phone ?? null,
            }));
        },
        [eventId],
    );

    const searchContacts = useCallback(async (term: string): Promise<PickerOption[]> => {
        const { data } = await api.get<ContactPage>('/api/v1/contacts', { params: { search: term } });
        return data.data.map((contact) => ({
            id: contact.id,
            label: contact.name,
            hint: contact.email ?? contact.phone ?? null,
        }));
    }, []);

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();
        setErrors({});

        const currency = form.currency.trim().toUpperCase();
        const clientErrors: Record<string, string[]> = {};

        if (!/^[A-Z]{3}$/.test(currency)) {
            clientErrors.currency = ['Enter a three-letter ISO-4217 currency code (e.g. USD, TZS).'];
        }
        const amountMajor = form.amount.trim() === '' ? Number.NaN : Number(form.amount);
        const amountMinor = majorToMinor(amountMajor, currency);
        if (amountMinor === null) {
            clientErrors.pledged_amount_minor = ['Enter a valid amount of 0 or more.'];
        }
        if (nameRequired && form.name.trim() === '') {
            clientErrors.pledger_name = ['A pledger name is required when no guest or contact is linked.'];
        }

        if (Object.keys(clientErrors).length > 0) {
            setErrors(clientErrors);
            return;
        }

        const payload: Record<string, unknown> = {
            guest_id: form.guest?.id ?? null,
            contact_id: form.contact?.id ?? null,
            pledger_email: form.email.trim() === '' ? null : form.email.trim(),
            pledger_phone: form.phone.trim() === '' ? null : form.phone.trim(),
            note: form.note.trim() === '' ? null : form.note.trim(),
            currency,
            pledged_amount_minor: amountMinor,
        };

        if (isEdit) {
            // On update the name is only sent when set, so a blank field keeps
            // the existing snapshot instead of failing the "required" rule.
            if (form.name.trim() !== '') payload.pledger_name = form.name.trim();
        } else {
            payload.pledger_name = form.name.trim() === '' ? null : form.name.trim();
        }

        setSaving(true);
        try {
            const { data } = isEdit
                ? await api.patch<Pledge>(`/api/v1/events/${eventId}/pledges/${pledge?.id}`, payload)
                : await api.post<Pledge>(`/api/v1/events/${eventId}/pledges`, payload);
            onSaved(data);
        } catch (err) {
            const fieldErrors = validationErrors(err);
            setErrors(fieldErrors);
            if (Object.keys(fieldErrors).length === 0) {
                onError(apiErrorMessage(err));
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            open={open}
            title={isEdit ? 'Edit pledge' : 'Record pledge'}
            onClose={onClose}
            busy={saving}
            maxWidth={560}
        >
            <form onSubmit={submit}>
                <p className="muted small" style={{ marginTop: 0 }}>
                    A pledge records what a client says they will give. Invitation Studio does not collect, hold, or
                    verify money.
                </p>

                <div className="grid-2">
                    <EntityPicker
                        label="Link to a guest (optional)"
                        placeholder="Search guests by name or email"
                        value={form.guest}
                        onChange={(guest) => setForm({ ...form, guest })}
                        search={searchGuests}
                        emptyText="No matching guests on this event."
                        helpText="Linking a guest links this pledge to that invitation record."
                    />
                    <EntityPicker
                        label="Link to a contact (optional)"
                        placeholder="Search contacts by name or email"
                        value={form.contact}
                        onChange={(contact) => setForm({ ...form, contact })}
                        search={searchContacts}
                        emptyText="No matching contacts."
                        helpText="Contacts are shared across your events."
                    />
                </div>

                <Field
                    label={nameRequired ? 'Pledger name' : 'Pledger name (optional override)'}
                    error={errors.pledger_name?.[0]}
                    htmlFor="pledge-name"
                >
                    <input
                        id="pledge-name"
                        className="input"
                        value={form.name}
                        onChange={(event) => setForm({ ...form, name: event.target.value })}
                        maxLength={255}
                    />
                </Field>

                <div className="grid-2">
                    <Field label="Email (optional)" error={errors.pledger_email?.[0]} htmlFor="pledge-email">
                        <input
                            id="pledge-email"
                            className="input"
                            type="email"
                            value={form.email}
                            onChange={(event) => setForm({ ...form, email: event.target.value })}
                            maxLength={255}
                        />
                    </Field>
                    <Field label="Phone (optional)" error={errors.pledger_phone?.[0]} htmlFor="pledge-phone">
                        <input
                            id="pledge-phone"
                            className="input"
                            value={form.phone}
                            onChange={(event) => setForm({ ...form, phone: event.target.value })}
                            maxLength={50}
                        />
                    </Field>
                </div>

                <div className="grid-2">
                    <Field label="Currency" error={errors.currency?.[0]} htmlFor="pledge-currency">
                        <input
                            id="pledge-currency"
                            className="input"
                            list="pledge-currency-options"
                            value={form.currency}
                            onChange={(event) => setForm({ ...form, currency: event.target.value.toUpperCase() })}
                            maxLength={3}
                            placeholder="USD"
                            disabled={hasFulfillments}
                            aria-describedby="pledge-currency-help"
                        />
                        <datalist id="pledge-currency-options">
                            {CURRENCY_SUGGESTIONS.map((code) => (
                                <option key={code} value={code} />
                            ))}
                        </datalist>
                    </Field>
                    <Field label="Pledged amount" error={errors.pledged_amount_minor?.[0]} htmlFor="pledge-amount">
                        <input
                            id="pledge-amount"
                            className="input"
                            inputMode="decimal"
                            value={form.amount}
                            onChange={(event) => setForm({ ...form, amount: event.target.value })}
                            placeholder="0.00"
                        />
                    </Field>
                </div>

                <p className="form-help" id="pledge-currency-help">
                    {hasFulfillments
                        ? 'The currency is fixed once a fulfillment has been reported. The pledged amount can still be corrected.'
                        : 'Enter the amount in the pledge currency.'}
                </p>

                <Field label="Note (optional)" error={errors.note?.[0]} htmlFor="pledge-note">
                    <textarea
                        id="pledge-note"
                        className="textarea"
                        rows={3}
                        value={form.note}
                        onChange={(event) => setForm({ ...form, note: event.target.value })}
                        maxLength={2000}
                    />
                </Field>

                {errors.guest_id?.[0] ? <div className="error-text">{errors.guest_id[0]}</div> : null}
                {errors.contact_id?.[0] ? <div className="error-text">{errors.contact_id[0]}</div> : null}

                <div className="modal-footer">
                    <button type="button" className="btn btn-ghost" onClick={onClose} disabled={saving}>
                        Cancel
                    </button>
                    <button
                        type="submit"
                        className="btn btn-primary"
                        disabled={saving || (nameRequired && form.name.trim() === '')}
                    >
                        {saving ? 'Saving…' : isEdit ? 'Save changes' : 'Record pledge'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
