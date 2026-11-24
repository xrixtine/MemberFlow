<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Organisation
    |--------------------------------------------------------------------------
    */

    'organisation' => env('MEMBERFLOW_ORGANISATION', 'MemberFlow Association'),

    /*
    |--------------------------------------------------------------------------
    | Initial administrator (used only by UserSeeder, CLI only)
    |--------------------------------------------------------------------------
    |
    | Fill these in your local .env file. They are never shown in the UI and
    | the seeder refuses to run when an administrator already exists.
    |
    */

    'admin' => [
        'name' => env('MEMBERFLOW_ADMIN_NAME', 'MemberFlow Administrator'),
        'email' => env('MEMBERFLOW_ADMIN_EMAIL'),
        'password' => env('MEMBERFLOW_ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Administrator sign-in email and password change (CLI only)
    |--------------------------------------------------------------------------
    |
    | php artisan memberflow:change-admin-credentials emails a one-time code to
    | the NEW address. These limits are fixed by design; tests may lower them.
    |
    */

    'admin_credentials' => [
        'code_ttl_minutes' => 15,
        'max_attempts' => 3,
        'lock_seconds' => 20 * 60,   // one run at a time per administrator; also frees an interrupted run
    ],

    /*
    |--------------------------------------------------------------------------
    | Shared hosting (e.g. InfinityFree)
    |--------------------------------------------------------------------------
    |
    | signature_ignored_query: query parameters a hosting provider may append to
    | a link before it reaches MemberFlow. InfinityFree's browser check reloads a
    | visitor's first request with "?i=1". These parameters are never part of a
    | signed link, so ignoring them does not weaken the signature check: changing
    | any signed value (expires, signature, path) still invalidates the link.
    |
    | trusted_proxies: set TRUSTED_PROXIES when the app runs behind a reverse
    | proxy that terminates HTTPS (InfinityFree: "*"), or to a comma-separated
    | list of proxy IPs. Leave it empty locally. Only X-Forwarded-Proto and
    | X-Forwarded-Port are trusted (never X-Forwarded-Host, which a client could
    | forge). The client IP is still taken from the connection unless
    | TRUSTED_PROXY_FORWARDED_FOR=true.
    |
    | force_https: make every generated URL https (FORCE_HTTPS=true). Use it on
    | an HTTPS host if links or assets are still generated with http://.
    |
    */

    'signature_ignored_query' => ['i'],

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    'trusted_proxy_forwarded_for' => (bool) env('TRUSTED_PROXY_FORWARDED_FOR', false),

    'force_https' => (bool) env('FORCE_HTTPS', false),

    /*
    |--------------------------------------------------------------------------
    | Database safety
    |--------------------------------------------------------------------------
    |
    | Destructive commands (migrate:fresh/refresh/reset/rollback, db:wipe) are
    | only allowed against this database. Automated tests refuse to run
    | against any other database.
    |
    */

    'testing_database' => 'memberflow_testing',

    /*
    |--------------------------------------------------------------------------
    | Business rules
    |--------------------------------------------------------------------------
    */

    'renewal_window_days' => 60,
    'verification_ttl_hours' => 48,
    'status_link_ttl_days' => 30,
    'invitation_ttl_hours' => 72,

    /*
    |--------------------------------------------------------------------------
    | Public membership applications (/apply)
    |--------------------------------------------------------------------------
    |
    | apply_min_seconds: a form submitted faster than this is treated as automated.
    | apply_form_max_minutes: an older form must be reloaded (stale form token).
    | verification_resend_cooldown_seconds: minimum gap between verification emails
    | to the same applicant (public resend and admin resend).
    |
    */

    'apply_min_seconds' => 3,
    'apply_form_max_minutes' => 120,
    'verification_resend_cooldown_seconds' => 60,

    /*
    |--------------------------------------------------------------------------
    | Email delivery
    |--------------------------------------------------------------------------
    |
    | Recipients on these reserved / fictional domains are recorded as
    | "skipped" and no email is dispatched (RFC 2606 / RFC 6761 names that can
    | never receive mail). Matching is on the domain and all its subdomains;
    | the reserved TLDs match any domain ending in them.
    |
    */

    'fictional_email_domains' => ['example.com', 'example.net', 'example.org'],
    'fictional_email_tlds' => ['test', 'example', 'invalid', 'localhost'],

    /*
    |--------------------------------------------------------------------------
    | Default map / weather location (Manila, per the course tutorial)
    |--------------------------------------------------------------------------
    */

    'default_latitude' => 14.5995,
    'default_longitude' => 120.9842,
    'default_location_label' => env('MEMBERFLOW_LOCATION_LABEL', 'Manila'),

];
