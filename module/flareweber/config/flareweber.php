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

    'compiler' => [
        'max_pages' => env('FLAREWEBER_MAX_COMPILE_PAGES', 50),
    ],
];
