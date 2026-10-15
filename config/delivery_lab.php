<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Delivery Lab (test-mode safeguards)
    |--------------------------------------------------------------------------
    |
    | When disabled (the default), delivery behaves exactly as configured by
    | the normal channels and mail transport. When enabled, outbound guest
    | messages are only sent to the explicit `recipients` allow list; every
    | other recipient is blocked. The guard never falls back to live delivery:
    | a blocked recipient is simply not sent to, and is never marked as sent.
    |
    | Secrets are intentionally absent here: the SMS/WhatsApp endpoints already
    | carry their own credentials via config/invitations.php.
    |
    */

    'enabled' => filter_var(env('DELIVERY_LAB_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Comma-separated list of allowed test recipients. Emails are compared
    | case-insensitively and phone numbers by their digit-only form.
    */
    'recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DELIVERY_LAB_RECIPIENTS', ''))
    ), fn ($recipient) => $recipient !== '')),

    /*
    | When true, SMS and WhatsApp are captured locally by the sandbox transport
    | and perform NO network I/O. Email continues to use the Laravel mailer
    | (log / array / a local Mailpit SMTP capture service).
    */
    'sandbox' => filter_var(env('DELIVERY_LAB_SANDBOX', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | The controlled outcome the sandbox transport simulates: "accepted" (the
    | default) or "failed". Used to exercise failure handling without a real
    | provider.
    */
    'sandbox_outcome' => env('DELIVERY_LAB_SANDBOX_OUTCOME', 'accepted'),

    /*
    | Minimum seconds between identical operator test sends (same guest/kind/
    | channel). Prevents accidental duplicate sends from repeated clicks.
    */
    'test_send_debounce_seconds' => (int) env('DELIVERY_LAB_TEST_SEND_DEBOUNCE', 5),

];
