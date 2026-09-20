<?php

namespace FlareWeber\Stripe;

use FlareWeber\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class StripeConnectService
{
    private const AUTHORIZE_URL = 'https://connect.stripe.com/oauth/authorize';

    private const TOKEN_URL = 'https://connect.stripe.com/oauth/token';

    public function authorizeUrl(Site $site): string
    {
        $state = Str::random(40);
        session(["flareweber.stripe_state_{$site->id}" => $state]);

        return self::AUTHORIZE_URL . '?' . http_build_query([
            'response_type' => 'code',
            'scope' => 'read_write',
            'client_id' => config('flareweber.stripe.client_id'),
            'redirect_uri' => route('flareweber.stripe.callback', $site),
            'state' => $state,
            'stripe_user[email]' => $site->settings['contact_email'] ?? null,
        ]);
    }

    public function completeConnect(Site $site, string $code, string $state): void
    {
        if ($state !== session()->pull("flareweber.stripe_state_{$site->id}")) {
            throw new \RuntimeException('Invalid Stripe OAuth state.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('flareweber.stripe.client_id'),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Stripe Connect failed: ' . $response->body());
        }

        $settings = $site->settings ?? [];
        $settings['stripe_account_id'] = $response->json('stripe_user_id');
        $settings['stripe_publishable_key'] = $response->json('stripe_publishable_key');
        $settings['ecommerce'] = true;

        $site->forceFill(['settings' => $settings])->save();
    }

    public function verifyWebhook(string $payload, string $signature): array
    {
        $secret = config('flareweber.stripe.webhook_secret');
        [$timestamp, $signatures] = $this->parseSignature($signature);

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", (string) $secret);

        if (!in_array($expected, explode(',', $signatures), true)) {
            throw new \RuntimeException('Invalid Stripe webhook signature.');
        }

        if (abs(time() - (int) $timestamp) > 300) {
            throw new \RuntimeException('Stripe webhook timestamp too old.');
        }

        return json_decode($payload, true) ?? [];
    }

    private function parseSignature(string $signature): array
    {
        $timestamp = '';
        $signatures = '';

        foreach (explode(',', $signature) as $part) {
            [$key, $value] = explode('=', $part, 2);
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures .= ($signatures ? ',' : '') . $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
