<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Browser-test identity and fixtures
    |--------------------------------------------------------------------------
    |
    | These values are only used by the guarded browser-test commands. The
    | dedicated browser-test database is separate from both the local
    | application database and the PHPUnit database.
    |
    */

    'user_name' => env('BROWSER_TEST_USER_NAME', 'Browser Test Organizer'),
    'user_email' => env('BROWSER_TEST_USER_EMAIL', 'browser.owner@example.test'),
    'user_password' => env('BROWSER_TEST_USER_PASSWORD'),
    'event_title' => env('BROWSER_TEST_EVENT_TITLE', 'Browser Test Event'),
    'empty_user_name' => env('BROWSER_TEST_EMPTY_USER_NAME', 'Empty Workspace Tester'),
    'empty_user_email' => env('BROWSER_TEST_EMPTY_USER_EMAIL', 'browser.empty@example.test'),
    'staff_user_name' => env('BROWSER_TEST_STAFF_USER_NAME', 'Browser Test Staff'),
    'staff_user_email' => env('BROWSER_TEST_STAFF_USER_EMAIL', 'browser.staff@example.test'),
    'celebration_name' => env('BROWSER_TEST_CELEBRATION_NAME', 'Browser Test Celebration'),
    'standalone_event_title' => env('BROWSER_TEST_STANDALONE_EVENT_TITLE', 'Browser Test Standalone Event'),
    'past_event_title' => env('BROWSER_TEST_PAST_EVENT_TITLE', 'Browser Test Past Event'),
    'upcoming_event_title' => env('BROWSER_TEST_UPCOMING_EVENT_TITLE', 'Browser Test Upcoming Event'),

];
