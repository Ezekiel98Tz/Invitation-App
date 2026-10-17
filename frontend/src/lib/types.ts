export type Role = 'admin' | 'staff' | string;

export interface User {
    id: number;
    name: string;
    email: string;
    role: Role;
    is_admin: boolean;
    is_staff: boolean;
    owner_id: number | null;
}

export type CapabilityKey =
    | 'custom_questions'
    | 'plus_ones'
    | 'waitlist'
    | 'check_in'
    | 'households'
    | 'contributions';

export type EventCapabilities = Partial<Record<CapabilityKey, boolean>>;

export interface EventSettings {
    allow_plus_ones?: boolean;
    custom_questions?: CustomQuestion[];
    enable_waitlist?: boolean;
    design?: Design;
    event_type?: string;
    capabilities?: EventCapabilities;
}

export interface EventTypeDefinition {
    label: string;
    description?: string;
    default_capabilities?: EventCapabilities;
}

export interface CapabilityDefinition {
    label: string;
    description?: string;
    implemented: boolean;
    default: boolean;
}

export interface EventTypeTaxonomy {
    default: string;
    types: Record<string, EventTypeDefinition>;
    capabilities: Record<string, CapabilityDefinition>;
}

export interface EventItem {
    id: number;
    title: string;
    description: string | null;
    event_start: string;
    timezone: string;
    venue: string;
    capacity: number | null;
    settings?: EventSettings;
    event_type?: string;
    capabilities?: EventCapabilities;
    celebration_id?: number | null;
    celebration?: { id: number; name: string } | null;
}

export interface Celebration {
    id: number;
    name: string;
    description: string | null;
    events_count?: number;
    created_at?: string;
    updated_at?: string;
}

export interface CelebrationDetail {
    celebration: Celebration;
    events: EventItem[];
    events_meta: PageMeta;
}

export interface Contact {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    notes: string | null;
    guests_count: number;
    created_at?: string | null;
    updated_at?: string | null;
}

export interface ContactPage extends Paginated<Contact> {}

export interface ContactGuest {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    status: GuestStatus;
    sent_at?: string | null;
    reminded_at?: string | null;
    event: { id: number; title: string; event_start: string | null } | null;
}

export interface ContactGuestPage extends Paginated<ContactGuest> {}

export type DeliveryLabMode = 'disabled' | 'sandbox' | 'provider';

export interface DeliveryLabChannelReadiness {
    configured: boolean;
    mode: 'sandbox' | 'provider' | 'unavailable';
    verified: boolean;
    mailer?: string;
}

export interface DeliveryLabLab {
    enabled: boolean;
    sandbox: boolean;
    mode: DeliveryLabMode;
    allowlisted_recipients: number;
}

export interface DeliveryLabCounts {
    queued: number;
    sent: number;
    delivered: number;
    failed: number;
    simulated: number;
    blocked: number;
}

export interface DeliveryLabDelivery {
    id: number;
    kind: string;
    channel: string;
    status: string;
    mode: 'sandbox' | 'provider';
    simulated: boolean;
    blocked: boolean;
    outcome: string | null;
    recipient: string | null;
    provider_reference: string | null;
    provider_status: string | null;
    error: string | null;
    captured_message: string | null;
    sent_at: string | null;
    delivered_at: string | null;
    failed_at: string | null;
    created_at: string | null;
    guest: { id: number; name: string } | null;
    event: { id: number; title: string } | null;
}

export interface DeliveryLabDashboard {
    lab: DeliveryLabLab;
    channels: Record<string, DeliveryLabChannelReadiness>;
    counts: DeliveryLabCounts;
    recent: DeliveryLabDelivery[];
}

export interface DeliveryLabPreviewContent {
    subject?: string;
    html?: string;
    text?: string;
    action_url?: string | null;
    message?: string;
    link?: string;
    character_count?: number;
    template_indication?: string;
}

export interface DeliveryLabPreview {
    kind: 'invitation' | 'reminder';
    channel: 'mail' | 'sms' | 'whatsapp';
    sandbox: boolean;
    content: DeliveryLabPreviewContent;
}

export interface DeliveryLabTestSendResult {
    outcome: 'captured' | 'accepted' | 'failed' | 'blocked';
    delivery: DeliveryLabDelivery;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    next_page_url: string | null;
    prev_page_url: string | null;
    total?: number;
    per_page?: number;
}

/** Pagination metadata for hand-shaped (non-Laravel-paginator) list responses. */
export interface PageMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export interface Rsvp {
    attending_count: number;
    answers: Record<string, unknown> | null;
}

export type GuestStatus = 'pending' | 'accepted' | 'declined';

export interface Guest {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    status: GuestStatus;
    invite_token?: string | null;
    rsvp?: Rsvp | null;
    sent_at?: string | null;
    reminded_at?: string | null;
}

export interface GuestImport {
    id: number;
    status: 'queued' | 'processing' | 'completed' | 'failed';
    original_filename: string;
    created_count: number;
    skipped_count: number;
    failed_count: number;
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
}

export interface GuestCounts {
    total: number;
    pending: number;
    accepted: number;
    declined: number;
}

export interface GuestPage extends Paginated<Guest> {
    counts?: GuestCounts;
}

/* ---- Event-day check-in / attendance ---- */

/**
 * The authoritative decision returned by the read-only check-in lookup.
 * Mirrors `CheckInController::decision()` on the backend.
 */
export type CheckInState =
    | 'valid'
    | 'invalid'
    | 'revoked'
    | 'expired'
    | 'not_yet'
    | 'ineligible'
    | 'already_used';

/**
 * Why a credential is (not) usable. Mirrors `App\Support\CredentialAccess`.
 */
export type CredentialReason =
    | 'valid'
    | 'not_active'
    | 'revoked'
    | 'expired'
    | 'unavailable'
    | 'not_yet';

/** Stored credential lifecycle status; `none` means no current credential. */
export type CredentialStatus = 'active' | 'issued' | 'revoked' | 'expired' | 'none';

export type AttendanceMethod = 'qr' | 'code' | 'manual' | 'override';
export type AttendanceStatus = 'valid' | 'reversed';

export interface CheckInLookupCredential {
    status: string;
    reason: CredentialReason;
}

export interface CheckInLookupGuest {
    id: number;
    name: string;
    status: GuestStatus;
}

export interface CheckInLookup {
    state: CheckInState;
    credential: CheckInLookupCredential | null;
    guest: CheckInLookupGuest | null;
    rsvp?: { attending_count: number | null } | null;
    checked_in: boolean;
    attendance?: { id: number; checked_in_at: string | null } | null;
}

export interface Attendance {
    id: number;
    guest_id: number;
    guest: { id: number; name: string } | null;
    method: AttendanceMethod | string;
    party_size: number;
    status: AttendanceStatus | string;
    checked_in_at: string | null;
    checked_in_by: number | null;
    reversed_at: string | null;
    reversed_by: number | null;
    reversal_reason: string | null;
    note: string | null;
}

export interface AttendanceList extends PageMeta {
    checked_in: number;
    attendances: Attendance[];
}

export interface CheckInResult {
    attendance: Attendance;
    guest: { id: number; name: string };
}

export interface ReverseAttendanceResult {
    attendance: Attendance;
}

/* ---- Entry credentials (operator control) ---- */

export interface InvitationCredential {
    id?: number;
    type?: string;
    status: CredentialStatus | string;
    issued_at?: string | null;
    activated_at?: string | null;
    revoked_at?: string | null;
    revoke_reason?: string | null;
    replaces_id?: number | null;
    valid: boolean;
    reason: CredentialReason | string;
}

/** Response shape shared by issue / reissue / reveal. */
export interface CredentialWithCode {
    credential: InvitationCredential;
    code: string;
}

export interface CredentialStateResponse {
    credential: InvitationCredential;
}

export interface CredentialQueuedResponse {
    queued: boolean;
}

export interface Delivery {
    id: number;
    kind: string;
    channel: string;
    status: string;
    error?: string | null;
    sent_at?: string | null;
    delivered_at?: string | null;
    failed_at?: string | null;
    created_at?: string | null;
    meta?: Record<string, unknown> | null;
    guest?: Guest | null;
}

/** The all-history endpoint paginates guests, with each guest's deliveries nested. */
export interface EventDeliveryGuest extends Guest {
    deliveries: Delivery[];
}

export interface EventDeliveryPage extends Paginated<EventDeliveryGuest> {}

/* ---- Guest communication ---- */

export type CommunicationChannelKey = 'mail' | 'sms' | 'whatsapp';
export type CommunicationKind = 'invitation' | 'reminder';

export interface CommunicationChannelOption {
    key: CommunicationChannelKey;
    label: string;
    available: boolean;
    configured: boolean;
    mode: string;
    reason: string | null;
    note: string | null;
}

export interface CommunicationChannelOptions {
    channels: CommunicationChannelOption[];
    delivery_lab_restricted: boolean;
}

export interface CommunicationRejectedRecipient {
    guest_id: number;
    name: string | null;
    reason: string;
}

export interface CommunicationQueueResult {
    queued: boolean | number;
    queued_count?: number;
    kind?: CommunicationKind;
    channel?: CommunicationChannelKey;
    rejected?: CommunicationRejectedRecipient[];
}

export interface CommunicationPreview {
    event_id: number;
    kind: CommunicationKind;
    recipient: { id: number | null; name: string };
    channels: {
        mail?: { subject: string; html?: string; text?: string };
        sms?: { message: string };
        whatsapp?: { message: string };
    };
    available_channels?: CommunicationChannelOptions;
}

export interface CustomQuestion {
    key: string;
    type?: 'text' | 'number' | 'boolean' | 'choice';
    required?: boolean;
    options?: string[];
}

export interface TemplateDefinition {
    name: string;
    occasion: string;
    layout: 'centered' | 'banner' | 'left';
    font: string;
    colors: Record<string, string>;
}

export interface Design {
    template: string;
    heading: string;
    hosts: string;
    message: string;
    rsvp_note: string;
    background_image: string | null;
    font: string;
    colors: Record<string, string>;
}

export interface InvitationDesignResponse {
    event: EventItem;
    design: Design;
    templates: Record<string, TemplateDefinition>;
    fonts: Record<string, string>;
}

export type PublicPassState =
    | 'valid'
    | 'pending'
    | 'declined'
    | 'not_active'
    | 'not_yet'
    | 'expired'
    | 'revoked'
    | 'disabled'
    | 'unavailable';

export interface PublicPass {
    guest: { name: string };
    event: {
        title: string;
        event_start: string | null;
        timezone: string;
        venue: string;
    } | null;
    pass: {
        state: PublicPassState;
        credential_status: string | null;
        qr_available: boolean;
        valid_from: string | null;
        valid_until: string | null;
        entry_code_note: string;
    };
}

export interface PublicInvite {
    guest: { id: number; name: string; status: GuestStatus };
    rsvp: Rsvp | null;
    event: EventItem;
    settings: {
        allow_plus_ones: boolean;
        custom_questions: CustomQuestion[];
        design: Design;
    };
}

export interface EventSummary {
    id: number;
    title: string;
    event_start: string | null;
    response_rate: number;
    guests_total: number;
    guests_accepted: number;
    guests_declined: number;
    guests_responded: number;
}

export interface EventReport extends PageMeta {
    total_events: number;
    events: EventSummary[];
}

/* ---- Pledges (information only — no money is moved or held) ---- */

/**
 * Lifecycle derived from the reported fulfillment ledger. Mirrors
 * `Pledge::derivedStatus()` on the backend. `over_fulfilled` is possible
 * because reported amounts are never clamped.
 */
export type PledgeDerivedStatus =
    | 'open'
    | 'partially_fulfilled'
    | 'fulfilled'
    | 'over_fulfilled'
    | 'cancelled';

/** Stored status. `active` and `cancelled` are the only values the API sets. */
export type PledgeStoredStatus = 'active' | 'cancelled';

export type PledgeSource = 'manual' | 'import';

export type FulfillmentMethod = 'cash' | 'bank_transfer' | 'mobile_money' | 'cheque' | 'other';

export type FulfillmentStatus = 'recorded' | 'reversed';

/** A reported fulfillment (append-only history). Mirrors `PledgeFulfillment`. */
export interface PledgeFulfillment {
    id: number;
    pledge_id: number;
    amount_minor: number;
    currency: string | null;
    method: FulfillmentMethod | string | null;
    method_detail: string | null;
    received_on: string | null;
    reference: string | null;
    note: string | null;
    recorded_by: number | null;
    status: FulfillmentStatus | string;
    /** Set on a compensating entry; points at the fulfillment it reverses. */
    reverses_fulfillment_id: number | null;
    is_reversal: boolean;
    created_at: string | null;
    updated_at: string | null;
}

/** A recorded pledge. Mirrors `PledgeController::pledgePayload()`. */
export interface Pledge {
    id: number;
    event_id: number;
    guest_id: number | null;
    contact_id: number | null;
    /** Snapshot name captured when the pledge was created (never auto-updated). */
    pledger_name: string | null;
    pledger_email: string | null;
    pledger_phone: string | null;
    /** ISO-4217 code shared by the pledge and all of its fulfillments. */
    currency: string;
    pledged_amount_minor: number;
    note: string | null;
    source: PledgeSource | string;
    status: PledgeStoredStatus | string;
    /** Net reported fulfillment (recorded minus reversed), in minor units. */
    fulfilled_minor: number;
    /** Never negative; over-fulfillment is surfaced through `derived_status`. */
    outstanding_minor: number;
    derived_status: PledgeDerivedStatus;
    fulfillments_count: number | null;
    /** Present on list/detail responses; `null` when the relation was not loaded. */
    fulfillments: PledgeFulfillment[] | null;
    created_by: number | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface PledgePage extends Paginated<Pledge> {}

/** Response from recording or reversing a fulfillment. */
export interface PledgeMutationResult {
    fulfillment: PledgeFulfillment;
    pledge: Pledge;
}

export interface PledgeDerivedCounts {
    open: number;
    partially_fulfilled: number;
    fulfilled: number;
    over_fulfilled: number;
    cancelled: number;
}

export interface PledgeStatusCounts {
    active: number;
    cancelled: number;
}

/** Per-(method, currency) reported totals — amounts are never summed across currencies. */
export interface PledgeMethodTotal {
    method: string;
    currency: string;
    entries: number;
    amount_minor: number;
}

/**
 * Money totals for one currency. Different currencies are never combined —
 * each gets its own row.
 */
export interface PledgeCurrencyTotal {
    currency: string;
    pledges: number;
    pledged_minor: number;
    fulfilled_minor: number;
    outstanding_minor: number;
}

export interface EventPledgeSummary {
    event_id: number;
    title: string;
    total_pledges: number;
    counts: PledgeDerivedCounts;
    status_counts: PledgeStatusCounts;
    by_method: PledgeMethodTotal[];
    currencies: PledgeCurrencyTotal[];
}

/** Per-event slice of an account or celebration roll-up. */
export interface PledgeEventBreakdown {
    event_id: number;
    title: string | null;
    total_pledges: number;
    counts: PledgeDerivedCounts;
    currencies: PledgeCurrencyTotal[];
}

export interface CelebrationPledgeReport {
    celebration_id: number;
    name: string;
    total_pledges: number;
    counts: PledgeDerivedCounts;
    status_counts: PledgeStatusCounts;
    by_method: PledgeMethodTotal[];
    currencies: PledgeCurrencyTotal[];
    event_count: number;
    events: PledgeEventBreakdown[];
}

/** Account-wide roll-up from `GET reports/pledges`. */
export interface AccountPledgeReport {
    total_pledges: number;
    counts: PledgeDerivedCounts;
    status_counts: PledgeStatusCounts;
    by_method: PledgeMethodTotal[];
    currencies: PledgeCurrencyTotal[];
    events: PledgeEventBreakdown[];
}

/* ---- Pledge spreadsheet import (operator workflow, information only) ---- */

/** Stored status of a pledge import batch. Mirrors `PledgeImportBatch`. */
export type PledgeImportBatchStatus = 'uploaded' | 'previewed' | 'processing' | 'completed' | 'failed';

/** Analysis/apply mode. Mirrors `PledgeImporter::MODE_*`. */
export type PledgeImportMode = 'create_only' | 'update_matched';

/** Proposed or applied action for one import row. Mirrors `PledgeImporter`. */
export type PledgeImportRowAction = 'create' | 'update' | 'skip' | 'ambiguous' | 'failed';

/** One canonical pledge-import field => detected column header. */
export type PledgeImportMapping = Record<string, string>;

/** One recognized file column mapped onto a canonical import field. */
export interface PledgeImportRecognizedColumn {
    header: string;
    key: string;
    label: string;
    required: boolean;
    legacy: boolean;
}

/** Operator-facing note about a column the importer will not use. */
export interface PledgeImportNotice {
    header: string;
    reason: string;
}

/** A required canonical column the file does not (yet) provide. */
export interface PledgeImportMissingColumn {
    header: string;
    label: string;
}

/** Header-recognition report returned by the upload endpoint. */
export interface PledgeImportRecognition {
    recognized: PledgeImportRecognizedColumn[];
    unsupported: string[];
    notices: PledgeImportNotice[];
    missing_required: PledgeImportMissingColumn[];
}

/** A read-only sample of the actual extracted cells from the uploaded file. */
export interface PledgeImportSampleRow {
    row: number;
    values: Record<string, string | number | null>;
}

/** A per-row operator decision, keyed by the 1-based data row number. */
export interface PledgeImportDecision {
    action: 'create' | 'update' | 'skip';
    pledge_id?: number;
}

/** The optional reported fulfillment attached to an import row. */
export interface PledgeImportFulfillment {
    amount_minor: number | null;
    received_on: string | null;
    reference: string | null;
    method: string | null;
    note: string | null;
}

/** One analysed import row. Mirrors `PledgeImportController::rowPayload()`. */
export interface PledgeImportRow {
    row: number;
    action: PledgeImportRowAction | string;
    reason: string | null;
    errors: Record<string, string>;
    pledger_name: string | null;
    pledger_email: string | null;
    pledger_phone: string | null;
    currency: string | null;
    pledged_amount_minor: number | null;
    guest_id: number | null;
    contact_id: number | null;
    pledge_id: number | null;
    fulfillment: PledgeImportFulfillment | null;
}

/**
 * Preview/confirm counts. `ambiguous` is the number of rows that were ambiguous
 * at analysis time (a decision was required), so it can be non-zero even after
 * every ambiguous row has been resolved by an explicit decision.
 */
export interface PledgeImportCounts {
    total_rows: number;
    created: number;
    updated: number;
    skipped: number;
    failed: number;
    ambiguous: number;
    fulfillments_created: number;
    fulfillments_skipped: number;
}

export interface PledgeImportBatch {
    id: number;
    event_id: number;
    status: PledgeImportBatchStatus | string;
    original_filename: string;
    columns: string[] | null;
    mapping: PledgeImportMapping | null;
    summary: Record<string, unknown> | null;
    created_by: number | null;
    created_at: string | null;
    updated_at: string | null;
}

/** `POST events/{event}/pledges/import/upload` response. */
export interface PledgeImportUploadResponse {
    batch: PledgeImportBatch;
    columns: string[];
    mapping: PledgeImportMapping;
    row_count: number;
    /** Configured maximum data rows for one workbook. */
    max_rows: number;
    recognition: PledgeImportRecognition;
    sample: PledgeImportSampleRow[];
}

/** Bounded preview pagination metadata. */
export interface PledgeImportPreviewMeta {
    page: number;
    per_page: number;
    last_page: number;
    total_rows: number;
}

/** `POST events/{event}/pledges/import/{batch}/preview` response. */
export interface PledgeImportPreviewResponse {
    batch: PledgeImportBatch;
    mapping: PledgeImportMapping;
    mode: PledgeImportMode;
    counts: PledgeImportCounts;
    /** One bounded page of rows; `counts`/`preview` still describe the whole file. */
    rows: PledgeImportRow[];
    /** Data-row numbers that are ambiguous across the WHOLE file (all pages). */
    ambiguous_rows: number[];
    preview: PledgeImportPreviewMeta;
}

/** `POST events/{event}/pledges/import/{batch}/confirm` response. */
export interface PledgeImportConfirmResponse {
    batch: PledgeImportBatch;
    counts: PledgeImportCounts;
    rows: PledgeImportRow[];
}
