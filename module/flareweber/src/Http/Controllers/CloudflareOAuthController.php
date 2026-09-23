<?php

namespace FlareWeber\Http\Controllers;

use FlareWeber\Cloudflare\CloudflareClient;
use FlareWeber\Cloudflare\CloudflareTokenService;
use FlareWeber\Cloudflare\OAuthService;
use FlareWeber\Models\CloudflareConnection;
use FlareWeber\Support\Handoff;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CloudflareOAuthController extends Controller
{
    use BrowserCallbackNotice;

    public function connect(Request $request, OAuthService $oauth)
    {
        if (!OAuthService::oauthAvailable()) {
            return response()->json([
                'error' => 'oauth_not_configured',
                'message' => 'Cloudflare OAuth is not configured on this install. Connect with an API token instead.',
                'required_permissions' => CloudflareTokenService::REQUIRED_PERMISSIONS,
            ], 409);
        }

        if (!$request->expectsJson()) {
            return redirect()->away($oauth->authorizeUrl());
        }

        $handoff = bin2hex(random_bytes(16));

        return response()->json([
            'url' => $oauth->authorizeUrl($handoff),
            'handoff' => $handoff,
        ]);
    }

    /** Contract G: connect with a user-created API token instead of OAuth. */
    public function connectWithToken(Request $request, CloudflareTokenService $tokens)
    {
        $data = $request->validate(['token' => 'required|string|max:512']);

        try {
            $result = $tokens->connect($data['token'], $request->user()?->id);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => Handoff::sanitize($e->getMessage()),
                'required_permissions' => CloudflareTokenService::REQUIRED_PERMISSIONS,
            ], 422);
        }

        if ($result['status'] === 'needs_account') {
            session([
                'flareweber.cf_pending_connection' => $result['connection_id'],
                'flareweber.cf_accounts' => $result['accounts'],
            ]);
        }

        return response()->json($result);
    }

    public function callback(Request $request, OAuthService $oauth)
    {
        $request->validate(['code' => 'required|string', 'state' => 'required|string']);

        try {
            $tokens = $oauth->exchangeCode($request->input('code'), $request->input('state'));
            $handoff = $oauth->pendingHandoff();
        } catch (\Throwable $e) {
            $handoff = $oauth->pendingHandoff();

            if ($handoff !== null) {
                $oauth->completeHandoff($handoff, Handoff::errorPayload($e->getMessage(), 'cloudflare'));

                return response($this->donePage('Connection failed'), 400);
            }

            return redirect('/flareweber/admin?error=cloudflare_failed#/settings');
        }

        $connection = new CloudflareConnection();
        $connection->user_id = $request->user()?->id;
        $connection->method = CloudflareConnection::METHOD_OAUTH;
        $connection->access_token = $tokens['access_token'];
        $connection->refresh_token = $tokens['refresh_token'] ?? null;
        $connection->token_expires_at = isset($tokens['expires_in'])
            ? now()->addSeconds((int) $tokens['expires_in'])
            : null;
        $connection->scopes = $tokens['scope'] ?? null;
        $connection->account_id = '';
        $connection->status = 'pending';
        $connection->save();

        try {
            $client = new CloudflareClient($connection, (string) config('flareweber.cloudflare.api_base'));
            $active = $client->verifyToken();
            $accounts = $active ? $client->accounts() : [];
        } catch (\Throwable) {
            $active = false;
            $accounts = [];
        }

        if (!$active || $accounts === []) {
            $connection->delete();
            $message = $active
                ? 'The Cloudflare token has no access to any account.'
                : 'The Cloudflare token was not active. Please try again.';

            if ($handoff !== null) {
                $oauth->completeHandoff($handoff, Handoff::errorPayload($message, 'cloudflare'));

                return response($this->donePage('Connection failed'), 400);
            }

            return redirect('/flareweber/admin?error=token_inactive#/settings');
        }

        if (count($accounts) === 1) {
            $this->finalizeAccount($connection, (string) ($accounts[0]['id'] ?? ''), (string) ($accounts[0]['name'] ?? ''));

            if ($handoff !== null) {
                $oauth->completeHandoff($handoff, $this->connectedPayload($connection));

                return response($this->donePage('Cloudflare connected'));
            }

            return redirect('/flareweber/admin#/new');
        }

        $accounts = array_map(
            static fn (array $a) => ['id' => $a['id'] ?? '', 'name' => $a['name'] ?? ''],
            $accounts
        );

        if ($handoff !== null) {
            $oauth->completeHandoff($handoff, [
                'status' => 'needs_account',
                'connection_id' => $connection->id,
                'accounts' => $accounts,
            ]);

            return response($this->donePage('Pick a Cloudflare account in FlareWeber'));
        }

        session(['flareweber.cf_pending_connection' => $connection->id, 'flareweber.cf_accounts' => $accounts]);

        return redirect('/flareweber/admin#/cloudflare-accounts');
    }

    /** Poll target for the desktop app while it waits on the system browser. */
    public function handoffStatus(string $handoff, OAuthService $oauth)
    {
        $payload = $oauth->handoffStatus($handoff);

        if ($payload === null) {
            return response()->json(['status' => 'expired'], 404);
        }

        return response()->json($payload);
    }

    public function accounts(Request $request, OAuthService $oauth)
    {
        if ($handoff = $request->query('handoff')) {
            $payload = $oauth->handoffStatus((string) $handoff) ?? [];

            return response()->json([
                'connection_id' => $payload['connection_id'] ?? null,
                'accounts' => $payload['accounts'] ?? [],
            ]);
        }

        return response()->json([
            'connection_id' => session('flareweber.cf_pending_connection'),
            'accounts' => session('flareweber.cf_accounts', []),
        ]);
    }

    public function selectAccount(Request $request, OAuthService $oauth)
    {
        $request->validate([
            'account_id' => 'required|string',
            'connection_id' => 'nullable|integer',
            'handoff' => 'nullable|string',
        ]);

        $handoff = $request->input('handoff');
        $handoffPayload = $handoff ? ($oauth->handoffStatus((string) $handoff) ?? []) : [];

        $connectionId = $request->input('connection_id')
            ?? $handoffPayload['connection_id']
            ?? session('flareweber.cf_pending_connection');

        $connection = CloudflareConnection::findOrFail($connectionId);

        if ($connection->status === 'connected' && $connection->account_id !== '') {
            return response()->json(['error' => 'already_connected'], 409);
        }

        // Re-list from Cloudflare so the choice is validated against the token.
        try {
            $client = new CloudflareClient($connection, (string) config('flareweber.cloudflare.api_base'));
            $accounts = $client->accounts();
        } catch (\Throwable) {
            $accounts = $handoffPayload['accounts'] ?? session('flareweber.cf_accounts', []);
        }

        $name = null;
        foreach ($accounts as $account) {
            if (($account['id'] ?? null) === $request->input('account_id')) {
                $name = (string) ($account['name'] ?? '');
                break;
            }
        }

        if ($name === null) {
            return response()->json(['error' => 'unknown_account'], 422);
        }

        $this->finalizeAccount($connection, (string) $request->input('account_id'), $name);

        if ($handoff) {
            $oauth->completeHandoff((string) $handoff, $this->connectedPayload($connection));
        }

        return response()->json(['connected' => true] + $this->connectedPayload($connection));
    }

    /** Contract G status shape. */
    public function status()
    {
        $connections = CloudflareConnection::query()->orderByDesc('id')->get();
        $current = $connections->first(fn (CloudflareConnection $c) => $c->isConnected());

        return response()->json([
            'connected' => $current !== null,
            'connection_id' => $current?->id,
            'account_id' => $current?->account_id,
            'account_name' => $current?->account_name,
            'method' => $current?->method,
            'oauth_available' => OAuthService::oauthAvailable(),
            'required_permissions' => CloudflareTokenService::REQUIRED_PERMISSIONS,
            'connections' => $connections->map(fn (CloudflareConnection $c) => [
                'id' => $c->id,
                'account_id' => $c->account_id,
                'account_name' => $c->account_name,
                'method' => $c->method,
                'status' => $c->status,
            ])->values(),
        ]);
    }

    /** Revoke (OAuth) and forget the tokens; sites keep their data. */
    public function disconnect(CloudflareConnection $connection)
    {
        if (!$connection->isTokenMethod()) {
            $this->revokeTokens($connection);
        }

        $connection->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'status' => 'disconnected',
        ])->save();

        return response()->json(['disconnected' => true, 'connection_id' => $connection->id]);
    }

    /**
     * Best-effort token revocation at Cloudflare; the refresh token is tried
     * first, then the access token. Any failure is swallowed so the local
     * disconnect always proceeds.
     */
    private function revokeTokens(CloudflareConnection $connection): void
    {
        $oauth = app(OAuthService::class);

        $tokens = [
            'refresh_token' => $connection->refresh_token,
            'access_token' => $connection->access_token,
        ];

        foreach ($tokens as $hint => $token) {
            if (!$token) {
                continue;
            }

            try {
                if ($oauth->revoke((string) $token, $hint)) {
                    return;
                }
            } catch (\Throwable) {
                continue;
            }
        }
    }

    private function finalizeAccount(CloudflareConnection $connection, string $accountId, string $name): void
    {
        $connection->forceFill([
            'account_id' => $accountId,
            'account_name' => $name,
            'status' => 'connected',
        ])->save();

        session()->forget(['flareweber.cf_pending_connection', 'flareweber.cf_accounts']);
    }

    /** @return array{status: string, connection_id: int, account_id: string, account_name: string|null} */
    private function connectedPayload(CloudflareConnection $connection): array
    {
        return [
            'status' => 'connected',
            'connection_id' => $connection->id,
            'account_id' => $connection->account_id,
            'account_name' => $connection->account_name,
        ];
    }
}
