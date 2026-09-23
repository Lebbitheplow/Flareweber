<?php

return [
    'cloudflare' => [
        'api_base' => env('CLOUDFLARE_API_BASE', 'https://api.cloudflare.com/client/v4'),
        'oauth_client_id' => env('CLOUDFLARE_OAUTH_CLIENT_ID', ''),
        // Optional: Cloudflare's OAuth client is a public PKCE client, so a
        // secret is only sent when one has been issued.
        'oauth_secret' => env('CLOUDFLARE_OAUTH_SECRET', ''),
        'oauth_redirect_uri' => env('CLOUDFLARE_OAUTH_REDIRECT_URI', ''),
        'oauth_scopes' => env(
            'CLOUDFLARE_OAUTH_SCOPES',
            'account:read user:read workers:write workers_scripts:write d1:write r2:write '
            . 'zone:read dns_records:write workers_routes:write ssl_certs:write offline_access'
        ),
        'worker_compatibility_date' => env('CLOUDFLARE_WORKER_COMPAT_DATE', '2025-09-01'),
    ],

    'stripe' => [
        // STRIPE_CLIENT_ID + STRIPE_SECRET_KEY are the platform credentials
        // used only for the Stripe Connect OAuth flow. Per-site keys live in
        // the site's encrypted secrets store.
        'client_id' => env('STRIPE_CLIENT_ID', ''),
        'secret_key' => env('STRIPE_SECRET_KEY', ''),
        // Platform webhook secret for Connect lifecycle events
        // (account.updated, account.application.deauthorized).
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
        // Pin the Connect redirect URI (use {site} for the site id). Desktop
        // sets this to the fixed loopback port Stripe was registered with.
        'redirect_uri' => env('STRIPE_REDIRECT_URI', ''),
    ],

    'worker' => [
        'template_path' => env('WORKER_TEMPLATE_PATH', base_path('worker')),
        'build_path' => env('WORKER_BUILD_PATH', storage_path('app/flareweber/builds')),
    ],

    'media' => [
        // Directory synced to the site's R2 bucket on publish. Defaults to
        // the Microweber media library (userfiles/media). Object keys are the
        // path relative to userfiles, so they always start with "media/".
        'source' => env('FLAREWEBER_MEDIA_SOURCE'),
        // Skip anything larger than this (R2 objects ship through the API).
        'max_file_bytes' => (int) env('FLAREWEBER_MEDIA_MAX_FILE_BYTES', 104857600),
    ],

    'compiler' => [
        'max_pages' => (int) env('FLAREWEBER_MAX_COMPILE_PAGES', 2000),
    ],

    'publish' => [
        // Run deployments in a detached "php artisan flareweber:run-deployment"
        // process so the HTTP request returns 202 immediately. Falls back to
        // running inline when the process cannot be spawned.
        'async' => (bool) env('FLAREWEBER_ASYNC_PUBLISH', true),
        'log_path' => env('FLAREWEBER_PUBLISH_LOG_PATH', storage_path('app/flareweber/logs')),
        // Running deployments older than this are considered abandoned.
        'stale_minutes' => (int) env('FLAREWEBER_PUBLISH_STALE_MINUTES', 30),
    ],
];
