<?php

use FlareWeber\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// Registered without the `web` middleware group so Stripe's server-to-server
// callbacks are not blocked by CSRF; authenticity is enforced via signature.
Route::post('stripe', [StripeWebhookController::class, 'stripe'])->name('stripe');
