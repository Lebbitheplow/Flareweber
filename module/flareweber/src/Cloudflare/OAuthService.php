<?php

namespace FlareWeber\Cloudflare;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OAuthService
{
    private const AUTHORIZE_URL = 'https://dash.cloudflare.com/oauth2/auth';

    private const TOKEN_URL = 'https://oauth.cloudflare.com/oauth2/token';

    public function authorizeUrl(): string
    {
        $state = Str::random(40);
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        session([
            'flareweber.cf_oauth_state' => $state,
            'flareweber.cf_oauth_verifier' => $verifier,
        ]);

        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => config('flareweber.cloudflare.oauth_client_id'),
            'response_type' => 'code',
            'scope' => config('flareweber.cloudflare.oauth_scopes'),
            'redirect_uri' => config('flareweber.cloudflare.oauth_redirect_uri'),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    public function exchangeCode(string $code, string $state): array
    {
        if ($state !== session()->pull('flareweber.cf_oauth_state')) {
            throw new \RuntimeException('Invalid OAuth state.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('flareweber.cloudflare.oauth_client_id'),
            'client_secret' => config('flareweber.cloudflare.oauth_secret'),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => session()->pull('flareweber.cf_oauth_verifier', ''),
            'redirect_uri' => config('flareweber.cloudflare.oauth_redirect_uri'),
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Cloudflare token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    public function refresh(string $refreshToken): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('flareweber.cloudflare.oauth_client_id'),
            'client_secret' => config('flareweber.cloudflare.oauth_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Cloudflare token refresh failed: ' . $response->body());
        }

        return $response->json();
    }
}
