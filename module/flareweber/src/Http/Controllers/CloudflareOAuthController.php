<?php

namespace FlareWeber\Http\Controllers;

use FlareWeber\Cloudflare\CloudflareClient;
use FlareWeber\Cloudflare\OAuthService;
use FlareWeber\Models\CloudflareConnection;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CloudflareOAuthController extends Controller
{
    public function connect(OAuthService $oauth)
    {
        return redirect()->away($oauth->authorizeUrl());
    }

    public function callback(Request $request, OAuthService $oauth)
    {
        $request->validate(['code' => 'required|string', 'state' => 'required|string']);

        $tokens = $oauth->exchangeCode($request->input('code'), $request->input('state'));

        $connection = new CloudflareConnection();
        $connection->user_id = $request->user()?->id;
        $connection->access_token = $tokens['access_token'];
        $connection->refresh_token = $tokens['refresh_token'] ?? null;
        $connection->token_expires_at = isset($tokens['expires_in'])
            ? now()->addSeconds($tokens['expires_in'])
            : null;
        $connection->scopes = $tokens['scope'] ?? null;
        $connection->account_id = '';
        $connection->save();

        $client = CloudflareClient::forConnection($connection);

        if (!$client->verifyToken()) {
            $connection->delete();

            return redirect('/flareweber/admin?error=token_inactive#/settings');
        }

        $accounts = $client->accounts();

        if (count($accounts) === 1) {
            $this->finalizeAccount($connection, $accounts[0]['id'] ?? '', $accounts[0]['name'] ?? '');

            return redirect('/flareweber/admin#/new');
        }

        session(['flareweber.cf_pending_connection' => $connection->id, 'flareweber.cf_accounts' => $accounts]);

        return redirect('/flareweber/admin#/cloudflare-accounts');
    }

    public function accounts()
    {
        return response()->json([
            'connection_id' => session('flareweber.cf_pending_connection'),
            'accounts' => session('flareweber.cf_accounts', []),
        ]);
    }

    public function selectAccount(Request $request)
    {
        $request->validate(['account_id' => 'required|string']);

        $connection = CloudflareConnection::findOrFail(session('flareweber.cf_pending_connection'));
        $accounts = session('flareweber.cf_accounts', []);

        $name = '';
        foreach ($accounts as $account) {
            if (($account['id'] ?? null) === $request->input('account_id')) {
                $name = $account['name'] ?? '';
            }
        }

        $this->finalizeAccount($connection, $request->input('account_id'), $name);

        return response()->json([
            'connected' => true,
            'account_id' => $connection->account_id,
            'account_name' => $connection->account_name,
        ]);
    }

    public function status()
    {
        $connection = CloudflareConnection::whereNotNull('account_id')
            ->where('status', 'connected')
            ->latest()
            ->first();

        if ($connection === null) {
            return response()->json(['connected' => false]);
        }

        return response()->json([
            'connected' => !$connection->isExpired(),
            'connection_id' => $connection->id,
            'account_id' => $connection->account_id,
            'account_name' => $connection->account_name,
        ]);
    }

    public function disconnect(CloudflareConnection $connection)
    {
        $connection->update(['status' => 'disconnected']);

        return response()->json(['disconnected' => true]);
    }

    private function finalizeAccount(CloudflareConnection $connection, string $accountId, string $name): void
    {
        $connection->update([
            'account_id' => $accountId,
            'account_name' => $name,
            'status' => 'connected',
        ]);

        session()->forget(['flareweber.cf_pending_connection', 'flareweber.cf_accounts']);
    }
}
