<?php

use FlareWeber\Http\Controllers\AdminController;
use FlareWeber\Http\Controllers\CloudflareOAuthController;
use FlareWeber\Http\Controllers\DomainController;
use FlareWeber\Http\Controllers\PublishController;
use FlareWeber\Http\Controllers\SiteController;
use FlareWeber\Http\Controllers\StripeConnectController;
use Illuminate\Support\Facades\Route;

Route::get('admin', [AdminController::class, 'index'])->name('admin');

Route::get('cloudflare/connect', [CloudflareOAuthController::class, 'connect'])->name('cloudflare.connect');
Route::get('cloudflare/callback', [CloudflareOAuthController::class, 'callback'])->name('cloudflare.callback');
Route::get('cloudflare/accounts', [CloudflareOAuthController::class, 'accounts'])->name('cloudflare.accounts');
Route::post('cloudflare/accounts/select', [CloudflareOAuthController::class, 'selectAccount'])->name('cloudflare.accounts.select');
Route::get('cloudflare/status', [CloudflareOAuthController::class, 'status'])->name('cloudflare.status');
Route::post('cloudflare/{connection}/disconnect', [CloudflareOAuthController::class, 'disconnect'])->name('cloudflare.disconnect');

Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
Route::post('sites', [SiteController::class, 'store'])->name('sites.store');
Route::get('sites/{site}', [SiteController::class, 'show'])->name('sites.show');
Route::match(['put', 'patch'], 'sites/{site}', [SiteController::class, 'update'])->name('sites.update');

Route::post('sites/{site}/publish', [PublishController::class, 'publish'])->name('sites.publish');
Route::post('sites/{site}/preview', [PublishController::class, 'preview'])->name('sites.preview');
Route::get('sites/{site}/deployments', [PublishController::class, 'deployments'])->name('sites.deployments');
Route::post('sites/{site}/deployments/{deployment}/rollback', [PublishController::class, 'rollback'])->name('sites.rollback');

Route::post('sites/{site}/domain/check', [DomainController::class, 'check'])->name('sites.domain.check');
Route::post('sites/{site}/domain/connect', [DomainController::class, 'connect'])->name('sites.domain.connect');

Route::get('sites/{site}/stripe/connect', [StripeConnectController::class, 'connect'])->name('sites.stripe.connect');
Route::get('sites/{site}/stripe/callback', [StripeConnectController::class, 'callback'])->name('sites.stripe.callback');
