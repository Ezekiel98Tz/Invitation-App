<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Event type taxonomy (configuration-driven)
    |--------------------------------------------------------------------------
    |
    | Event types are a *classification* and a source of *presentation metadata*
    | and *defaults*. They deliberately do NOT decide which capabilities an
    | event may use: any capability can be enabled on any event.
    |
    | The taxonomy is intentionally configuration-based (a plain array keyed by
    | a stable string) rather than a database table, because types are static
    | reference data that ships with the code and needs no per-organizer
    | editing, no ordering and no referential integrity in this phase. A future
    | phase can promote this to a table (organizer-editable custom types)
    | without changing the string keys already persisted on events.
    |
    | `default_capabilities` is the documented extension point for type-driven
    | capability defaults. It is intentionally empty for every type today so
    | that selecting a type never changes the effective behaviour of an event
    | (which would violate backward compatibility). It is NOT wedding-only:
    | with `check_in` and `contributions` now implemented, any type may opt in
    | by listing them here. A type default is overridden by the event's own
    | stored `settings.capabilities` value, and no type opts in today, so all
    | capabilities remain default-off.
    |
    */

    'default' => 'custom',

    'types' => [
        'wedding' => [
            'label' => 'Wedding',
            'description' => 'A wedding ceremony or reception.',
            'default_capabilities' => [],
        ],
        'send_off' => [
            'label' => 'Send-off',
            'description' => 'A send-off celebration.',
            'default_capabilities' => [],
        ],
        'kitchen_party' => [
            'label' => 'Kitchen party',
            'description' => 'A kitchen party celebration.',
            'default_capabilities' => [],
        ],
        'birthday' => [
            'label' => 'Birthday',
            'description' => 'A birthday celebration.',
            'default_capabilities' => [],
        ],
        'graduation' => [
            'label' => 'Graduation',
            'description' => 'A graduation celebration.',
            'default_capabilities' => [],
        ],
        'corporate' => [
            'label' => 'Corporate event',
            'description' => 'A corporate or professional event.',
            'default_capabilities' => [],
        ],
        'custom' => [
            'label' => 'Custom occasion',
            'description' => 'Any other occasion.',
            'default_capabilities' => [],
        ],
    ],

];
