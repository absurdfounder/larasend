<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Open Registration
    |--------------------------------------------------------------------------
    |
    | Registration is always available while the instance has no users, so
    | the first person to visit can create the owner account. After that,
    | new members are invited from workspace settings. Set this to true to
    | keep public self-registration open after the first user exists.
    |
    */

    'open_registration' => env('LARASEND_OPEN_REGISTRATION', false),

    /*
    |--------------------------------------------------------------------------
    | Marketing Landing Page
    |--------------------------------------------------------------------------
    |
    | Most self-hosted installs run on a private or internal-only domain, so
    | there's no reason for an anonymous visitor to land on public marketing
    | copy there — they should go straight to sign-in (or setup, if nobody
    | owns the instance yet). Set this to true only for a public-facing
    | instance that intentionally serves as the project's marketing site.
    |
    */

    'show_landing_page' => env('LARASEND_SHOW_LANDING_PAGE', false),

    /*
    |--------------------------------------------------------------------------
    | Trooper Platform Admin API
    |--------------------------------------------------------------------------
    |
    | Bearer token for the /api/admin/* provisioning routes used by the
    | Trooper central server. The admin API returns 503 until this is set.
    | The platform block describes the shared Trooper mail domain and the
    | provider credentials cloned into every organization project's source.
    |
    */

    'admin_token' => env('LARASEND_ADMIN_TOKEN'),

    'platform' => [
        'workspace_slug' => env('LARASEND_PLATFORM_WORKSPACE_SLUG', 'trooper-platform'),
        'router_project_slug' => env('LARASEND_PLATFORM_ROUTER_SLUG', 'platform-router'),
        'mail_domain' => env('LARASEND_PLATFORM_MAIL_DOMAIN'),
        'provider' => env('LARASEND_PLATFORM_PROVIDER', 'cloudflare'),
        'cloudflare_api_token' => env('LARASEND_PLATFORM_CF_API_TOKEN'),
        'cloudflare_account_id' => env('LARASEND_PLATFORM_CF_ACCOUNT_ID'),
        'aws_access_key_id' => env('LARASEND_PLATFORM_AWS_ACCESS_KEY_ID'),
        'aws_secret_access_key' => env('LARASEND_PLATFORM_AWS_SECRET_ACCESS_KEY'),
        'ses_region' => env('LARASEND_PLATFORM_SES_REGION', 'us-east-1'),
    ],

];
