<?php

namespace FlareWeber\Http\Controllers;

use FlareWeber\Stripe\StripeConnectService;
use FlareWeber\Stripe\StripeWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class StripeWebhookController extends Controller
{
    public function stripe(Request $request, StripeConnectService $stripe, StripeWebhookHandler $handler)
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('stripe-signature', '');

        try {
            $event = $stripe->verifyWebhook($payload, $signature);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        if ($event === []) {
            return response()->json(['error' => 'malformed_payload'], 400);
        }

        try {
            $result = $handler->handle($event);
        } catch (\Throwable $e) {
            Log::warning('FlareWeber Stripe webhook handler error: ' . $e->getMessage());

            return response()->json(['error' => 'handler_error'], 500);
        }

        return response()->json(['received' => true] + $result);
    }
}
