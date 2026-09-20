<?php

namespace FlareWeber\Stripe;

use FlareWeber\Models\Site;

/**
 * Handles Stripe Connect lifecycle events delivered to the platform webhook.
 *
 * Order-level events (e.g. checkout.session.completed) are handled by the
 * per-site Worker against its own D1; this handler only tracks the merchant
 * account connection so the app knows when payouts/details are ready or when
 * the site has been deauthorized.
 */
class StripeWebhookHandler
{
    /** @var callable(string): ?Site */
    private $siteResolver;

    public function __construct(?callable $siteResolver = null)
    {
        $this->siteResolver = $siteResolver
            ?? static fn (string $accountId): ?Site => Site::findByStripeAccount($accountId);
    }

    public function handle(array $event): array
    {
        $type = (string) ($event['type'] ?? '');
        $accountId = (string) ($event['account'] ?? ($event['data']['object']['id'] ?? ''));

        return match ($type) {
            'account.updated' => $this->accountUpdated($accountId, $event['data']['object'] ?? []),
            'account.application.deauthorized' => $this->accountDeauthorized($accountId),
            default => ['handled' => false, 'type' => $type],
        };
    }

    private function accountUpdated(string $accountId, array $account): array
    {
        $site = ($this->siteResolver)($accountId);

        if ($site === null) {
            return ['handled' => false, 'reason' => 'unknown_account', 'account' => $accountId];
        }

        $settings = $site->settings ?? [];
        $settings['stripe_charges_enabled'] = (bool) ($account['charges_enabled'] ?? false);
        $settings['stripe_details_submitted'] = (bool) ($account['details_submitted'] ?? false);
        $settings['stripe_payouts_enabled'] = (bool) ($account['payouts_enabled'] ?? false);
        $settings['stripe_connect_ready'] = $settings['stripe_charges_enabled']
            && $settings['stripe_details_submitted'];

        $site->forceFill(['settings' => $settings])->save();

        return [
            'handled' => true,
            'type' => 'account.updated',
            'site_id' => $site->id,
            'connect_ready' => $settings['stripe_connect_ready'],
        ];
    }

    private function accountDeauthorized(string $accountId): array
    {
        $site = ($this->siteResolver)($accountId);

        if ($site === null) {
            return ['handled' => false, 'reason' => 'unknown_account', 'account' => $accountId];
        }

        $settings = $site->settings ?? [];
        $settings['ecommerce'] = false;
        $settings['stripe_disconnected_at'] = now()->toIso8601String();

        $site->forceFill(['settings' => $settings])->save();

        return ['handled' => true, 'type' => 'account.application.deauthorized', 'site_id' => $site->id];
    }
}
