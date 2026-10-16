<?php

return [
    'channels' => array_filter(explode(',', (string) env('INVITATION_CHANNELS', 'mail'))),

    'notify_admin_on_rsvp' => (bool) env('INVITATIONS_NOTIFY_ADMIN_ON_RSVP', true),

    'token_ttl_days' => env('INVITE_TOKEN_TTL_DAYS'),

    // Default currency for pledge amounts when a row (and the event) do not
    // specify one. Kept configurable; never assume a single currency.
    'pledge_default_currency' => env('INVITATIONS_PLEDGE_DEFAULT_CURRENCY', 'USD'),

    // Entry-credential validity window, relative to event start. Independent of
    // the RSVP link expiry in InvitationAccess.
    'credential_valid_before_hours' => (int) env('INVITATION_CREDENTIAL_VALID_BEFORE_HOURS', 6),
    'credential_valid_after_hours' => (int) env('INVITATION_CREDENTIAL_VALID_AFTER_HOURS', 12),

    'webhook_secret' => env('INVITATIONS_WEBHOOK_SECRET'),

    'rate_limits' => [
        'admin_write_per_minute' => (int) env('INVITATIONS_ADMIN_WRITE_PER_MINUTE', 60),
        'admin_send_per_minute' => (int) env('INVITATIONS_ADMIN_SEND_PER_MINUTE', 20),
        'admin_heavy_per_minute' => (int) env('INVITATIONS_ADMIN_HEAVY_PER_MINUTE', 10),
        'admin_read_per_minute' => (int) env('INVITATIONS_ADMIN_READ_PER_MINUTE', 120),
        'invite_token_ip_per_minute' => (int) env('INVITATIONS_INVITE_IP_PER_MINUTE', 30),
        'invite_token_per_minute' => (int) env('INVITATIONS_INVITE_TOKEN_PER_MINUTE', 5),
    ],

    'sms' => [
        'endpoint' => env('SMS_ENDPOINT'),
        'token' => env('SMS_TOKEN'),
        'sender' => env('SMS_SENDER'),
    ],

    'whatsapp' => [
        'endpoint' => env('WHATSAPP_ENDPOINT'),
        'token' => env('WHATSAPP_TOKEN'),
        'sender' => env('WHATSAPP_SENDER'),
    ],
];
