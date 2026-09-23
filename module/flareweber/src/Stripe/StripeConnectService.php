<?php

namespace FlareWeber\Stripe;

use FlareWeber\Models\Site;
use FlareWeber\Support\Handoff;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Per-site Stripe connection: either Stripe Connect OAuth (platform
 * credentials) or a user supplied secret key (contract G). Either way the
 * site ends up with `stripe_secret_key` in its secrets and
 * `stripe_account_id` + `stripe_method` in its settings.
 */
class StripeConnectService
{
    private const AUTHORIZE_URL = 'https://connect.stripe.com/oauth/authorize';

    private const TOKEN_URL = 'https://connect.stripe.com/oauth/token';

    private const DEAUTHORIZE_URL = 'https://connect.stripe.com/oauth/deauthorize';

    private const API = 'https://api.stripe.com/v1';

    public const WEBHOOK_EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',
        'charge.refunded',
    ];

    /** Handoff token for an exchange finished in another browser. */
    private ?string $handoffToken = null;

    public function __construct(private readonly Handoff $handoff)
    {
    }

    /** Connect OAuth needs both platform credentials. */
    public static function oauthAvailable(): bool
    {
        return trim((string) config('flareweber.stripe.client_id', '')) !== ''
            && trim((string) config('flareweber.stripe.secret_key', '')) !== '';
    }

    /** @return array{connected: bool, method: string|null, account_id: string|null, oauth_available: bool} */
    public function status(Site $site): array
    {
        $method = $site->stripeMethod();

        return [
            'connected' => $method !== null,
            'method' => $method,
            'account_id' => $method !== null ? (string) ($site->settings['stripe_account_id'] ?? '') : null,
            'oauth_available' => self::oauthAvailable(),
        ];
    }

    public function authorizeUrl(Site $site, ?string $handoff = null): string
    {
        $state = Str::random(40);

        if ($handoff !== null) {
            $this->handoff->start($state, $handoff, ['site_id' => $site->id]);
        } else {
            session(["flareweber.stripe_state_{$site->id}" => $state]);
        }

        return self::AUTHORIZE_URL . '?' . http_build_query([
            'response_type' => 'code',
            'scope' => 'read_write',
            'client_id' => config('flareweber.stripe.client_id'),
            'redirect_uri' => $this->redirectUri($site),
            'state' => $state,
            'stripe_user[email]' => $site->settings['contact_email'] ?? null,
        ]);
    }

    /**
     * Stripe rejects custom URI schemes, so desktop installs must use the
     * loopback URL. Setting flareweber.stripe.redirect_uri (with a {site}
     * placeholder) pins it, e.g. to a fixed port the user registers once.
     */
    public function redirectUri(Site $site): string
    {
        $configured = (string) config('flareweber.stripe.redirect_uri', '');

        if ($configured !== '') {
            return str_replace('{site}', (string) $site->id, $configured);
        }

        return route('flareweber.stripe.callback', $site);
    }

    /** Handoff token for the exchange handled in this request, if any. */
    public function handoffToken(): ?string
    {
        return $this->handoffToken;
    }

    public function completeConnect(Site $site, string $code, string $state): void
    {
        $entry = $this->handoff->pullState($state);

        if ($entry !== null) {
            $this->handoffToken = $entry['handoff'] ?? null;

            if ((int) ($entry['site_id'] ?? 0) !== (int) $site->id) {
                $this->failHandoff('That Stripe authorisation was started for a different site.');
                throw new \RuntimeException('Stripe OAuth state does not match this site.');
            }
        } elseif ($state !== session()->pull("flareweber.stripe_state_{$site->id}")) {
            throw new \RuntimeException('Invalid Stripe OAuth state.');
        }

        $response = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
            'client_id' => config('flareweber.stripe.client_id'),
            'client_secret' => config('flareweber.stripe.secret_key'),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        if (!$response->successful() || !is_string($response->json('access_token'))) {
            $this->failHandoff('Stripe rejected the connection.');
            throw new \RuntimeException('Stripe Connect failed (HTTP ' . $response->status() . ').');
        }

        $this->storeConnection(
            $site,
            'connect',
            (string) $response->json('stripe_user_id'),
            (string) $response->json('access_token'),
            [
                'stripe_publishable_key' => $response->json('stripe_publishable_key'),
            ],
            ['stripe_refresh_token' => $response->json('refresh_token')]
        );

        if ($this->handoffToken !== null) {
            $this->handoff->putResult($this->handoffToken, [
                'status' => 'connected',
                'service' => 'stripe',
                'site_id' => $site->id,
                'stripe_account_id' => $site->settings['stripe_account_id'] ?? null,
            ]);
        }
    }

    /**
     * Connect with a secret (or restricted) API key by verifying it against
     * GET /v1/account. Returns the account id.
     */
    public function connectWithKey(Site $site, string $secretKey): string
    {
        $secretKey = trim($secretKey);

        if (preg_match('/^(sk|rk)_(live|test)_[A-Za-z0-9]+$/', $secretKey) !== 1) {
            throw new \RuntimeException('That does not look like a Stripe secret key (sk_... or rk_...).');
        }

        $response = Http::withToken($secretKey)->timeout(20)->get(self::API . '/account');

        if (!$response->successful() || !is_string($response->json('id'))) {
            throw new \RuntimeException('Stripe did not accept that key (HTTP ' . $response->status() . ').');
        }

        $accountId = (string) $response->json('id');

        $this->storeConnection($site, 'key', $accountId, $secretKey, [
            'stripe_livemode' => (bool) ($response->json('livemode') ?? str_starts_with($secretKey, 'sk_live')),
        ]);

        return $accountId;
    }

    /** Forget the site's Stripe credentials; ecommerce stays configurable. */
    public function disconnect(Site $site): void
    {
        $method = $site->stripeMethod();
        $accountId = (string) ($site->settings['stripe_account_id'] ?? '');

        if ($method === 'connect' && $accountId !== '' && self::oauthAvailable()) {
            try {
                Http::asForm()->withToken((string) config('flareweber.stripe.secret_key'))->timeout(15)
                    ->post(self::DEAUTHORIZE_URL, [
                        'client_id' => config('flareweber.stripe.client_id'),
                        'stripe_user_id' => $accountId,
                    ]);
            } catch (\Throwable) {
                // Best effort; local disconnect always proceeds.
            }
        }

        $settings = $site->settings ?? [];
        unset(
            $settings['stripe_account_id'],
            $settings['stripe_method'],
            $settings['stripe_publishable_key'],
            $settings['stripe_livemode'],
            $settings['stripe_connect_ready'],
            $settings['stripe_charges_enabled'],
            $settings['stripe_details_submitted'],
            $settings['stripe_payouts_enabled']
        );
        $settings['stripe_disconnected_at'] = now()->toIso8601String();

        $site->forceFill(['settings' => $settings])->save();
        $site->putSecrets([
            'stripe_secret_key' => null,
            'stripe_refresh_token' => null,
            'stripe_webhook_secret' => null,
        ]);
    }

    /**
     * Register (or reuse) the Worker's webhook endpoint on the site's Stripe
     * account using the site's own key, and store the signing secret. When
     * an endpoint exists for the URL but no secret is on file it is
     * recreated, because Stripe only reveals the secret at creation.
     */
    public function registerWebhook(Site $site): ?string
    {
        $secretKey = (string) $site->secret('stripe_secret_key');
        $siteUrl = $site->liveUrl();

        if ($secretKey === '' || $siteUrl === null) {
            return null;
        }

        $url = rtrim($siteUrl, '/') . '/api/webhooks/stripe';
        $existingSecret = (string) $site->secret('stripe_webhook_secret');

        $list = Http::withToken($secretKey)->timeout(20)->get(self::API . '/webhook_endpoints', ['limit' => 100]);
        $endpoints = $list->successful() ? (array) ($list->json('data') ?? []) : [];

        foreach ($endpoints as $endpoint) {
            if (($endpoint['url'] ?? '') !== $url) {
                continue;
            }

            if ($existingSecret !== '' && ($endpoint['status'] ?? 'enabled') === 'enabled') {
                return $existingSecret;
            }

            Http::withToken($secretKey)->timeout(20)->delete(self::API . '/webhook_endpoints/' . $endpoint['id']);
        }

        $response = Http::asForm()->withToken($secretKey)->timeout(20)->post(self::API . '/webhook_endpoints', [
            'url' => $url,
            'enabled_events' => self::WEBHOOK_EVENTS,
            'description' => 'FlareWeber site ' . $site->name,
        ]);

        $secret = $response->json('secret');

        if (!$response->successful() || !is_string($secret) || $secret === '') {
            throw new \RuntimeException(
                'Stripe webhook registration failed: ' . Handoff::sanitize((string) ($response->json('error.message') ?? 'HTTP ' . $response->status()))
            );
        }

        $site->putSecrets(['stripe_webhook_secret' => $secret]);

        return $secret;
    }

    /** Verify a platform (Connect lifecycle) webhook signature. */
    public function verifyWebhook(string $payload, string $signature): array
    {
        $secret = (string) config('flareweber.stripe.webhook_secret', '');

        if ($secret === '') {
            throw new \RuntimeException('No Stripe webhook secret configured (flareweber.stripe.webhook_secret).');
        }

        [$timestamp, $signatures] = $this->parseSignature($signature);

        if ($timestamp === '' || $signatures === []) {
            throw new \RuntimeException('Malformed Stripe signature header.');
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        $valid = false;

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $valid = true;
            }
        }

        if (!$valid) {
            throw new \RuntimeException('Invalid Stripe webhook signature.');
        }

        if (abs(time() - (int) $timestamp) > 300) {
            throw new \RuntimeException('Stripe webhook timestamp too old.');
        }

        $event = json_decode($payload, true);

        return is_array($event) ? $event : [];
    }

    /**
     * @param array<string, mixed> $settings   extra settings to merge
     * @param array<string, string|null> $secrets   extra secrets to merge
     */
    private function storeConnection(Site $site, string $method, string $accountId, string $secretKey, array $settings = [], array $secrets = []): void
    {
        if ($accountId === '' || $secretKey === '') {
            throw new \RuntimeException('Stripe returned no account or key.');
        }

        $merged = $site->settings ?? [];
        $merged['stripe_account_id'] = $accountId;
        $merged['stripe_method'] = $method;
        $merged['ecommerce'] = true;
        unset($merged['stripe_disconnected_at']);

        foreach ($settings as $key => $value) {
            if ($value !== null) {
                $merged[$key] = $value;
            }
        }

        $site->forceFill(['settings' => $merged])->save();
        $site->putSecrets(['stripe_secret_key' => $secretKey, 'stripe_webhook_secret' => null] + $secrets);
    }

    private function failHandoff(string $message): void
    {
        if ($this->handoffToken !== null) {
            $this->handoff->putResult($this->handoffToken, Handoff::errorPayload($message, 'stripe'));
        }
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function parseSignature(string $signature): array
    {
        $timestamp = '';
        $signatures = [];

        foreach (explode(',', $signature) as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = explode('=', trim($part), 2);

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
