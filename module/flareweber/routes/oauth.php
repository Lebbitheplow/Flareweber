<?php

use FlareWeber\Http\Controllers\CloudflareOAuthController;
use FlareWeber\Http\Controllers\DesktopSessionController;
use FlareWeber\Http\Controllers\StripeConnectController;
use Illuminate\Support\Facades\Route;

// OAuth round-trip endpoints. The callbacks are hit by the system browser, which
// has no admin session, so these sit outside the admin auth gate and are
// guarded by the OAuth state / handoff token instead.
Route::get('cloudflare/callback', [CloudflareOAuthController::class, 'callback'])->name('cloudflare.callback');
Route::get('sites/{site}/stripe/callback', [StripeConnectController::class, 'callback'])->name('stripe.callback');
Route::get('handoff/{handoff}/status', [CloudflareOAuthController::class, 'handoffStatus'])->name('handoff.status');

// Desktop auto-login: Electron passes a per-launch token via the
// FLAREWEBER_DESKTOP_TOKEN environment variable (contract H).
Route::get('desktop/session', DesktopSessionController::class)->name('desktop.session');
