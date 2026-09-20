<?php

return [
    'cloudflare' => [
        'api_base' => env('CLOUDFLARE_API_BASE', 'https://api.cloudflare.com/client/v4'),
        'oauth_client_id' => env('CLOUDFLARE_OAUTH_CLIENT_ID', ''),
        'oauth_secret' => env('CLOUDFLARE_OAUTH_SECRET', ''),
        'oauth_redirect_uri' => env('CLOUDFLARE_OAUTH_REDIRECT_URI', ''),
        'oauth_scopes' => env(
            'CLOUDFLARE_OAUTH_SCOPES',
            'user.read workers:write workers_routes:write workers_tail:read d1:write r2:write zone:read ssl_cert:write'
        ),
        'worker_compatibility_date' => env('CLOUDFLARE_WORKER_COMPAT_DATE', '2025-09-01'),
    ],

    'stripe' => [
        'client_id' => env('STRIPE_CLIENT_ID', ''),
        'secret_key' => env('STRIPE_SECRET_KEY', ''),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
    ],

    'worker' => [
        'template_path' => env('WORKER_TEMPLATE_PATH', base_path('worker')),
        'build_path' => env('WORKER_BUILD_PATH', storage_path('app/flareweber/builds')),
    ],

    'media' => [
        // Directory synced to the site's R2 bucket on publish. Defaults to the
        // bundled Microweber media library (public/userfiles).
        'source' => env('FLAREWEBER_MEDIA_SOURCE'),
        // Key prefix inside the R2 bucket; also the Worker /media/* path.
        'prefix' => env('FLAREWEBER_MEDIA_PREFIX', 'media'),
        // Skip anything larger than this (R2 objects ship through the API).
        'max_file_bytes' => (int) env('FLAREWEBER_MEDIA_MAX_FILE_BYTES', 104857600),
    ],

    'compiler' => [
        'max_pages' => env('FLAREWEBER_MAX_COMPILE_PAGES', 50),
    ],
];
