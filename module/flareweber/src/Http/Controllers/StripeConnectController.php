<?php

namespace FlareWeber\Http\Controllers;

use FlareWeber\Models\Site;
use FlareWeber\Stripe\StripeConnectService;
use FlareWeber\Support\Handoff;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class StripeConnectController extends Controller
{
    use BrowserCallbackNotice;

    public function status(Site $site, StripeConnectService $stripe)
    {
        return response()->json($stripe->status($site));
    }

    public function connect(Request $request, Site $site, StripeConnectService $stripe)
    {
        if (!StripeConnectService::oauthAvailable()) {
            return response()->json([
                'error' => 'oauth_not_configured',
                'message' => 'Stripe Connect is not configured on this install. Paste a secret key instead.',
            ], 409);
        }

        if (!$request->expectsJson()) {
            return redirect()->away($stripe->authorizeUrl($site));
        }

        $handoff = bin2hex(random_bytes(16));

        return response()->json([
            'url' => $stripe->authorizeUrl($site, $handoff),
            'handoff' => $handoff,
        ]);
    }

    public function callback(Request $request, StripeConnectService $stripe, Site $site)
    {
        $data = $request->validate(['code' => 'required|string', 'state' => 'required|string']);

        try {
            $stripe->completeConnect($site, $data['code'], $data['state']);
        } catch (\Throwable $e) {
            if ($stripe->handoffToken() !== null) {
                return response($this->donePage('Stripe connection failed'), 400);
            }

            return redirect('/flareweber/admin?error=stripe_failed#/sites/' . $site->id);
        }

        if ($stripe->handoffToken() !== null) {
            return response($this->donePage('Stripe connected'));
        }

        return redirect('/flareweber/admin#/sites/' . $site->id);
    }

    /** Connect with a user supplied secret or restricted key. */
    public function key(Request $request, Site $site, StripeConnectService $stripe)
    {
        $data = $request->validate(['secret_key' => 'required|string|max:255']);

        try {
            $accountId = $stripe->connectWithKey($site, $data['secret_key']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'invalid_key',
                'message' => Handoff::sanitize($e->getMessage()),
            ], 422);
        }

        return response()->json(['connected' => true, 'method' => 'key', 'account_id' => $accountId]);
    }

    public function disconnect(Site $site, StripeConnectService $stripe)
    {
        $stripe->disconnect($site);

        return response()->json($stripe->status($site->fresh()));
    }
}
