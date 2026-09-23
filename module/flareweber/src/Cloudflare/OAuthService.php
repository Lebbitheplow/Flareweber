<?php

namespace FlareWeber\Cloudflare;

use FlareWeber\Models\CloudflareConnection;
use FlareWeber\Support\Handoff;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Cloudflare OAuth (same endpoints Wrangler uses: dash.cloudflare.com/oauth2).
 */
class OAuthService
{
    private const AUTHORIZE_URL = 'https://dash.cloudflare.com/oauth2/auth';

    private const TOKEN_URL = 'https://dash.cloudflare.com/oauth2/token';

    private const REVOKE_URL = 'https://dash.cloudflare.com/oauth2/revoke';

    /** Handoff token tied to the state being exchanged in this request. */
    private ?string $pendingHandoff = null;

    public function __construct(private readonly Handoff $handoff)
    {
    }

    /** OAuth is usable once a client id has been configured. */
    public static function oauthAvailable(): bool
    {
        return trim((string) config('flareweber.cloudflare.oauth_client_id', '')) !== '';
    }

    /**
     * Start the OAuth dance. When a handoff token is supplied, the state and
     * PKCE verifier are parked in the shared cache instead of the session so
     * the code can be exchanged from a different browser (the desktop app
     * authorises in the system browser and polls the result back in).
     */
    public function authorizeUrl(?string $handoff = null): string
    {
        $state = Str::random(40);
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        if ($handoff !== null) {
            $this->handoff->start($state, $handoff, ['verifier' => $verifier]);
        } else {
            session([
                'flareweber.cf_oauth_state' => $state,
                'flareweber.cf_oauth_verifier' => $verifier,
            ]);
        }

        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => config('flareweber.cloudflare.oauth_client_id'),
            'response_type' => 'code',
            'scope' => config('flareweber.cloudflare.oauth_scopes'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, scope?: string}
     */
    public function exchangeCode(string $code, string $state): array
    {
        $entry = $this->handoff->pullState($state);

        if ($entry !== null) {
            $verifier = (string) ($entry['verifier'] ?? '');
            $this->pendingHandoff = $entry['handoff'] ?? null;
        } else {
            if ($state !== session()->pull('flareweber.cf_oauth_state')) {
                throw new \RuntimeException('Invalid OAuth state.');
            }

            $verifier = (string) session()->pull('flareweber.cf_oauth_verifier', '');
        }

        $response = Http::asForm()->timeout(30)->post(self::TOKEN_URL, $this->clientCredentials() + [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $this->redirectUri(),
        ]);

        // Never echo the response body: it may carry tokens or the code.
        if (!$response->successful()) {
            throw new \RuntimeException('Cloudflare token exchange failed (HTTP ' . $response->status() . ').');
        }

        $tokens = $response->json();

        if (!is_array($tokens) || empty($tokens['access_token']) || !is_string($tokens['access_token'])) {
            throw new \RuntimeException('Cloudflare token exchange returned no access token.');
        }

        return $tokens;
    }

    /**
     * The registered redirect URI. Desktop installs point this at the
     * flareweber:// custom scheme (intercepted by Electron); web installs fall
     * back to the loopback/domain callback route.
     */
    public function redirectUri(): string
    {
        $configured = (string) config('flareweber.cloudflare.oauth_redirect_uri', '');

        if ($configured !== '') {
            return $configured;
        }

        return $this->callbackUrl();
    }

    /** Where Cloudflare sends the browser back to on this install. */
    public function callbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/flareweber/cloudflare/callback';
    }

    /** Handoff token registered for the state exchanged in this request. */
    public function pendingHandoff(): ?string
    {
        return $this->pendingHandoff;
    }

    /**
     * Record the outcome of a handoff so the desktop app can poll for it
     * instead of relying on a shared browser session.
     */
    public function completeHandoff(string $handoff, array $payload): void
    {
        $this->handoff->putResult($handoff, $payload);
    }

    public function handoffStatus(string $handoff): ?array
    {
        return $this->handoff->result($handoff);
    }

    public function refresh(string $refreshToken): array
    {
        $response = Http::asForm()->timeout(30)->post(self::TOKEN_URL, $this->clientCredentials() + [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Cloudflare token refresh failed (HTTP ' . $response->status() . ').');
        }

        $tokens = $response->json();

        if (!is_array($tokens) || empty($tokens['access_token'])) {
            throw new \RuntimeException('Cloudflare token refresh returned no access token.');
        }

        return $tokens;
    }

    /**
     * Rotate the tokens on a stored connection using its refresh token. The
     * connection's access token, refresh token, expiry and scopes are updated
     * and persisted on success; failures bubble up to the caller.
     */
    public function refreshConnection(CloudflareConnection $connection): void
    {
        $tokens = $this->refresh((string) $connection->refresh_token);

        $connection->access_token = $tokens['access_token'];

        if (!empty($tokens['refresh_token'])) {
            $connection->refresh_token = $tokens['refresh_token'];
        }

        if (isset($tokens['expires_in'])) {
            $connection->token_expires_at = now()->addSeconds((int) $tokens['expires_in']);
        }

        if (isset($tokens['scope'])) {
            $connection->scopes = $tokens['scope'];
        }

        $connection->status = 'connected';
        $connection->save();
    }

    /**
     * Revoke a single OAuth token. Returns whether Cloudflare accepted the
     * revocation; network errors are surfaced to the caller to handle.
     */
    public function revoke(string $token, string $hint = 'refresh_token'): bool
    {
        $response = Http::asForm()->timeout(15)->post(self::REVOKE_URL, $this->clientCredentials() + [
            'token' => $token,
            'token_type_hint' => $hint,
        ]);

        return $response->successful();
    }

    /** @return array<string, string> */
    private function clientCredentials(): array
    {
        $credentials = ['client_id' => (string) config('flareweber.cloudflare.oauth_client_id')];
        $secret = (string) config('flareweber.cloudflare.oauth_secret', '');

        if ($secret !== '') {
            $credentials['client_secret'] = $secret;
        }

        return $credentials;
    }
}
