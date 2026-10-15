<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Invitation template definitions (configuration-driven)
    |--------------------------------------------------------------------------
    |
    | Each template defines default palette, font pairing and layout. Event
    | owners can override the editable fields (colors, fonts, text) per event;
    | the chosen values are stored on the event's `settings.design` JSON.
    |
    */

    'default' => 'classic-white',

    'fonts' => [
        'serif' => '"Playfair Display", Georgia, "Times New Roman", serif',
        'sans' => '"Figtree", system-ui, -apple-system, "Segoe UI", sans-serif',
        'script' => '"Brush Script MT", "Segoe Script", cursive',
        'modern' => '"Montserrat", "Segoe UI", sans-serif',
    ],

    'templates' => [
        'classic-white' => [
            'name' => 'Classic White',
            'occasion' => 'wedding',
            'layout' => 'centered',
            'font' => 'serif',
            'colors' => [
                'background' => '#ffffff',
                'surface' => '#f8fafc',
                'primary' => '#1f2937',
                'accent' => '#b45309',
                'text' => '#111827',
            ],
        ],
        'blush-romance' => [
            'name' => 'Blush Romance',
            'occasion' => 'wedding',
            'layout' => 'centered',
            'font' => 'script',
            'colors' => [
                'background' => '#fff5f7',
                'surface' => '#ffe4ea',
                'primary' => '#9d174d',
                'accent' => '#db2777',
                'text' => '#4c0519',
            ],
        ],
        'midnight-gold' => [
            'name' => 'Midnight Gold',
            'occasion' => 'corporate',
            'layout' => 'banner',
            'font' => 'modern',
            'colors' => [
                'background' => '#0f172a',
                'surface' => '#1e293b',
                'primary' => '#f8fafc',
                'accent' => '#d4af37',
                'text' => '#e2e8f0',
            ],
        ],
        'emerald-garden' => [
            'name' => 'Emerald Garden',
            'occasion' => 'birthday',
            'layout' => 'centered',
            'font' => 'serif',
            'colors' => [
                'background' => '#f0fdf4',
                'surface' => '#dcfce7',
                'primary' => '#065f46',
                'accent' => '#16a34a',
                'text' => '#052e16',
            ],
        ],
        'minimal-slate' => [
            'name' => 'Minimal Slate',
            'occasion' => 'general',
            'layout' => 'left',
            'font' => 'sans',
            'colors' => [
                'background' => '#ffffff',
                'surface' => '#f1f5f9',
                'primary' => '#0f172a',
                'accent' => '#2563eb',
                'text' => '#1e293b',
            ],
        ],
    ],

];
