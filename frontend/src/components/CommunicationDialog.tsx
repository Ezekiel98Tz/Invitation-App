import { useEffect, useState } from 'react';
import { api, apiErrorMessage } from '../lib/api';
import type {
    CommunicationChannelKey,
    CommunicationChannelOption,
    CommunicationChannelOptions,
    CommunicationKind,
    CommunicationPreview,
    CommunicationQueueResult,
    CommunicationRejectedRecipient,
    Guest,
} from '../lib/types';
import { Alert, LoadingBlock } from './ui';
import { Modal } from './Modal';

export type CommunicationScope = 'individual' | 'selected' | 'all-unsent' | 'all-reminder-eligible';

interface Props {
    open: boolean;
    eventId: string;
    kind: CommunicationKind;
    scope: CommunicationScope;
    guests?: Guest[];
    onClose: () => void;
    onCompleted?: () => void;
}

interface CommunicationOutcome {
    queuedCount: number;
    rejected: CommunicationRejectedRecipient[];
    channelLabel: string;
}

function guestHasDestination(guest: Guest, channel: CommunicationChannelKey): boolean {
    if (channel === 'mail') return Boolean(guest.email?.trim());
    return Boolean(guest.phone?.trim());
}

function localReviewReason(
    guest: Guest,
    kind: CommunicationKind,
    channel: CommunicationChannelKey,
    scope: CommunicationScope,
): string | null {
    if (!guestHasDestination(guest, channel)) {
        return channel === 'mail'
            ? 'No email address is available for this guest.'
            : 'No phone number is available for this guest.';
    }

    if (kind === 'invitation' && scope !== 'individual' && guest.sent_at) {
        return 'A provider has already accepted an invitation for this guest. Use Resend invitation for a fresh attempt.';
    }

    if (kind === 'reminder') {
        if (guest.status !== 'pending') return 'This guest is not pending.';
        if (guest.reminded_at) return 'A reminder has already been accepted for this guest.';

        if (scope !== 'individual') {
            if (!guest.sent_at) return 'No provider-accepted invitation is available to follow up on.';
            const sentAt = new Date(guest.sent_at).getTime();
            if (!Number.isNaN(sentAt) && sentAt > Date.now() - 2 * 24 * 60 * 60 * 1000) {
                return 'The invitation was accepted less than two days ago.';
            }
        }
    }

    return null;
}

export function CommunicationDialog({
    open,
    eventId,
    kind,
    scope,
    guests = [],
    onClose,
    onCompleted,
}: Props) {
    const [options, setOptions] = useState<CommunicationChannelOptions | null>(null);
    const [optionsLoading, setOptionsLoading] = useState(false);
    const [optionsError, setOptionsError] = useState<string | null>(null);
    const [channel, setChannel] = useState<CommunicationChannelKey | ''>('');
    const [preview, setPreview] = useState<CommunicationPreview | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [outcome, setOutcome] = useState<CommunicationOutcome | null>(null);
    const [reloadOptions, setReloadOptions] = useState(0);

    const individual = scope === 'individual';
    const batchBySelection = scope === 'selected';
    const allScope = scope === 'all-unsent' || scope === 'all-reminder-eligible';
    const selectedGuests = individual || batchBySelection ? guests : [];

    useEffect(() => {
        if (!open) return;

        setChannel('');
        setPreview(null);
        setPreviewError(null);
        setError(null);
        setOutcome(null);
        setSubmitting(false);

        const controller = new AbortController();
        setOptionsLoading(true);
        setOptionsError(null);

        void api
            .get<CommunicationChannelOptions>(`/api/v1/events/${eventId}/communication/channels`, {
                signal: controller.signal,
            })
            .then(({ data }) => {
                if (!controller.signal.aborted) setOptions(data);
            })
            .catch((err) => {
                if (!controller.signal.aborted) setOptionsError(apiErrorMessage(err));
            })
            .finally(() => {
                if (!controller.signal.aborted) setOptionsLoading(false);
            });

        return () => controller.abort();
    }, [open, eventId, reloadOptions]);

    const previewGuest = selectedGuests[0];

    useEffect(() => {
        if (!open || channel === '' || outcome) return;

        const controller = new AbortController();
        setPreviewLoading(true);
        setPreviewError(null);

        void api
            .get<CommunicationPreview>(`/api/v1/events/${eventId}/invitations/preview`, {
                params: {
                    kind,
                    ...(previewGuest ? { guest_id: previewGuest.id } : {}),
                },
                signal: controller.signal,
            })
            .then(({ data }) => {
                if (!controller.signal.aborted) setPreview(data);
            })
            .catch((err) => {
                if (!controller.signal.aborted) setPreviewError(apiErrorMessage(err));
            })
            .finally(() => {
                if (!controller.signal.aborted) setPreviewLoading(false);
            });

        return () => controller.abort();
    }, [open, channel, eventId, kind, previewGuest?.id, outcome]);

    const channelOptions = options?.channels ?? [];
    const selectedOption: CommunicationChannelOption | null =
        channel === '' ? null : channelOptions.find((option) => option.key === channel) ?? null;

    const ineligible = selectedOption && selectedGuests.length > 0
        ? selectedGuests
              .map((guest) => ({
                  guest,
                  reason: localReviewReason(guest, kind, selectedOption.key, scope),
              }))
              .filter((entry): entry is { guest: Guest; reason: string } => entry.reason !== null)
        : [];

    const potentiallyEligible = selectedGuests.length - ineligible.length;
    const canSubmit =
        !submitting &&
        selectedOption !== null &&
        selectedOption.available &&
        (allScope || potentiallyEligible > 0);

    const submitCount = individual ? 1 : batchBySelection ? potentiallyEligible : null;
    const submitNoun = `${kind}${submitCount === 1 ? '' : 's'}`;
    const submitLabel = submitCount === null
        ? `Queue ${submitNoun}`
        : `Queue ${submitCount} ${submitNoun}`;

    const operationTitle = kind === 'invitation'
        ? individual
            ? 'Resend invitation'
            : 'Send invitations'
        : individual
          ? 'Send reminder'
          : 'Send reminders';

    const scopeCopy = individual
        ? 'One guest'
        : batchBySelection
          ? `${guests.length} selected guest${guests.length === 1 ? '' : 's'} on this page`
          : scope === 'all-unsent'
            ? 'All guests whose invitation has not yet been provider-accepted'
            : 'All pending, unreminded guests whose invitation was provider-accepted at least two days ago';

    const submit = async () => {
        if (!canSubmit || !selectedOption) return;

        setSubmitting(true);
        setError(null);

        try {
            let response;

            if (individual) {
                const guest = guests[0];
                const path = kind === 'invitation'
                    ? `/api/v1/events/${eventId}/guests/${guest.id}/resend`
                    : `/api/v1/events/${eventId}/guests/${guest.id}/remind`;

                response = await api.post<CommunicationQueueResult>(path, { channel: selectedOption.key });
            } else {
                const path = kind === 'invitation'
                    ? `/api/v1/events/${eventId}/invitations/send`
                    : `/api/v1/events/${eventId}/guests/reminders`;

                response = await api.post<CommunicationQueueResult>(path, {
                    channel: selectedOption.key,
                    ...(batchBySelection ? { guest_ids: guests.map((guest) => guest.id) } : {}),
                });
            }

            const data = response.data;
            const queuedCount = typeof data.queued === 'number'
                ? data.queued
                : data.queued_count ?? (data.queued ? 1 : 0);

            setOutcome({
                queuedCount,
                rejected: data.rejected ?? [],
                channelLabel: selectedOption.label,
            });
            onCompleted?.();
        } catch (err) {
            setError(apiErrorMessage(err));
        } finally {
            setSubmitting(false);
        }
    };

    const previewContent = preview && channel !== '' ? preview.channels[channel] : null;

    return (
        <Modal
            open={open}
            title={operationTitle}
            onClose={onClose}
            busy={submitting}
            maxWidth={640}
        >
            <div className="communication-dialog">
                {outcome ? (
                    <div className="communication-result" role="status" aria-live="polite">
                        <Alert kind={outcome.queuedCount > 0 ? 'success' : 'info'}>
                            {outcome.queuedCount > 0
                                ? `${outcome.queuedCount} ${kind}${outcome.queuedCount === 1 ? '' : 's'} queued via ${outcome.channelLabel}.`
                                : `No ${kind}s were queued.`}
                        </Alert>
                        <p className="muted">
                            These are queued attempts, not confirmed deliveries. Track their status in Delivery History.
                        </p>
                        {outcome.rejected.length > 0 ? (
                            <section className="communication-rejections" aria-labelledby="communication-rejected-title">
                                <h3 id="communication-rejected-title">
                                    {outcome.rejected.length} recipient{outcome.rejected.length === 1 ? '' : 's'} not queued
                                </h3>
                                <ul>
                                    {outcome.rejected.map((rejected) => (
                                        <li key={`${rejected.guest_id}-${rejected.reason}`}>
                                            <strong>{rejected.name ?? `Guest #${rejected.guest_id}`}</strong>
                                            <span>{rejected.reason}</span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ) : null}
                        <div className="modal-footer">
                            <button type="button" className="btn btn-primary" onClick={onClose}>
                                Done
                            </button>
                        </div>
                    </div>
                ) : (
                    <>
                        <div className="communication-intro">
                            <p>
                                <strong>{scopeCopy}</strong>
                            </p>
                            <p className="muted">
                                Choose a channel and review the operation before queueing. Delivery is not immediate.
                            </p>
                        </div>

                        {optionsLoading ? <LoadingBlock label="Loading available channels…" /> : null}
                        {optionsError ? (
                            <div className="stack">
                                <Alert kind="error">{optionsError}</Alert>
                                <button
                                    type="button"
                                    className="btn btn-ghost"
                                    onClick={() => setReloadOptions((value) => value + 1)}
                                >
                                    Try again
                                </button>
                            </div>
                        ) : null}

                        {options ? (
                            <>
                                {options.delivery_lab_restricted ? (
                                    <Alert kind="info">
                                        Delivery Lab is enabled. Only recipients on its test allowlist can be queued.
                                    </Alert>
                                ) : null}

                                <fieldset className="channel-choice">
                                    <legend>Communication channel</legend>
                                    <div className="channel-choice-list">
                                        {channelOptions.map((option) => (
                                            <label
                                                key={option.key}
                                                className={`channel-choice-option${option.available ? '' : ' is-unavailable'}`}
                                            >
                                                <input
                                                    type="radio"
                                                    name="communication-channel"
                                                    value={option.key}
                                                    checked={channel === option.key}
                                                    disabled={!option.available || submitting}
                                                    onChange={() => {
                                                        setChannel(option.key);
                                                        setError(null);
                                                    }}
                                                />
                                                <span className="channel-choice-copy">
                                                    <strong>{option.label}</strong>
                                                    {option.available ? (
                                                        <span>{option.note}</span>
                                                    ) : (
                                                        <span>{option.reason}</span>
                                                    )}
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                </fieldset>

                                {channelOptions.every((option) => !option.available) ? (
                                    <Alert kind="info">
                                        No communication channel is available in this environment. Configure a provider or enable the channel before sending.
                                    </Alert>
                                ) : null}
                            </>
                        ) : null}

                        {selectedOption && selectedGuests.length > 0 ? (
                            <section className="communication-review" aria-labelledby="communication-review-title">
                                <div className="section-head">
                                    <div>
                                        <h3 id="communication-review-title">Review recipients</h3>
                                        <p className="muted section-sub">
                                            {potentiallyEligible} of {selectedGuests.length} selected guest
                                            {selectedGuests.length === 1 ? '' : 's'} can be queued on {selectedOption.label}.
                                        </p>
                                    </div>
                                </div>
                                {ineligible.length > 0 ? (
                                    <ul className="communication-rejections-list">
                                        {ineligible.map(({ guest, reason }) => (
                                            <li key={guest.id}>
                                                <strong>{guest.name}</strong>
                                                <span>{reason}</span>
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                            </section>
                        ) : null}

                        {selectedOption ? (
                            <section className="communication-preview" aria-labelledby="communication-preview-title">
                                <div className="section-head">
                                    <div>
                                        <h3 id="communication-preview-title">Message preview</h3>
                                        {previewGuest ? (
                                            <p className="muted section-sub">
                                                Preview for {previewGuest.name}. Each recipient receives their own private RSVP link.
                                            </p>
                                        ) : (
                                            <p className="muted section-sub">
                                                Preview content is composed from this event’s existing message templates.
                                            </p>
                                        )}
                                    </div>
                                </div>
                                {previewLoading ? <LoadingBlock label="Loading message preview…" /> : null}
                                {previewError ? <Alert kind="error">{previewError}</Alert> : null}
                                {!previewLoading && previewContent ? (
                                    <div className="communication-preview-content">
                                        {selectedOption.key === 'mail' && 'subject' in previewContent ? (
                                            <>
                                                <p><strong>Subject</strong> · {previewContent.subject}</p>
                                                {previewContent.text ? <pre>{previewContent.text}</pre> : null}
                                            </>
                                        ) : 'message' in previewContent ? (
                                            <pre>{previewContent.message}</pre>
                                        ) : (
                                            <p className="muted">No preview is available for this channel.</p>
                                        )}
                                    </div>
                                ) : null}
                            </section>
                        ) : null}

                        {error ? <Alert kind="error">{error}</Alert> : null}

                        <div className="modal-footer">
                            <button type="button" className="btn btn-ghost" onClick={onClose} disabled={submitting}>
                                Cancel
                            </button>
                            <button
                                type="button"
                                className="btn btn-primary"
                                disabled={!canSubmit}
                                aria-busy={submitting}
                                onClick={() => void submit()}
                            >
                                {submitting ? 'Queueing…' : submitLabel}
                            </button>
                        </div>
                    </>
                )}
            </div>
        </Modal>
    );
}
