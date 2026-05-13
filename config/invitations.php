<?php

return [
    'channels' => array_filter(explode(',', (string) env('INVITATION_CHANNELS', 'mail'))),

    'notify_admin_on_rsvp' => (bool) env('INVITATIONS_NOTIFY_ADMIN_ON_RSVP', true),

    'token_ttl_days' => env('INVITE_TOKEN_TTL_DAYS'),

    'webhook_secret' => env('INVITATIONS_WEBHOOK_SECRET'),

    'rate_limits' => [
        'admin_write_per_minute' => (int) env('INVITATIONS_ADMIN_WRITE_PER_MINUTE', 60),
        'admin_send_per_minute' => (int) env('INVITATIONS_ADMIN_SEND_PER_MINUTE', 20),
        'admin_heavy_per_minute' => (int) env('INVITATIONS_ADMIN_HEAVY_PER_MINUTE', 10),
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
