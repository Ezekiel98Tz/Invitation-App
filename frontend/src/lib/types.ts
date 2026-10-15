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

export interface Delivery {
    id: number;
    kind: string;
    channel: string;
    status: string;
    error?: string | null;
    guest?: Guest | null;
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

export interface EventReport {
    total_events: number;
    events: EventSummary[];
}
