<?php

namespace FlareWeber\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Site extends Model
{
    use SoftDeletes;

    protected $table = 'flare_sites';

    protected $guarded = ['id'];

    protected $casts = [
        'settings' => 'array',
        'secrets' => 'encrypted:array',
        'media_manifest' => 'array',
        'published_at' => 'datetime',
    ];

    protected $hidden = [
        'secrets',
        'media_manifest',
    ];

    public function cloudflareConnection(): BelongsTo
    {
        return $this->belongsTo(CloudflareConnection::class, 'cloudflare_connection_id');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class, 'site_id');
    }

    public function latestDeployment(): ?Deployment
    {
        return $this->deployments()->latest('id')->first();
    }

    public function runningDeployment(): ?Deployment
    {
        return $this->deployments()->where('status', 'running')->latest('id')->first();
    }

    public static function findByStripeAccount(string $accountId): ?self
    {
        if ($accountId === '') {
            return null;
        }

        return static::query()
            ->where('settings->stripe_account_id', $accountId)
            ->first();
    }

    public function secret(string $key): ?string
    {
        $secrets = $this->secrets;

        return is_array($secrets) && isset($secrets[$key]) ? (string) $secrets[$key] : null;
    }

    /**
     * Merge values into the site's encrypted secrets store and persist them.
     *
     * @param array<string, string|null> $values
     */
    public function putSecrets(array $values): void
    {
        $secrets = $this->secrets ?? [];

        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($secrets[$key]);
            } else {
                $secrets[$key] = $value;
            }
        }

        $this->forceFill(['secrets' => $secrets])->save();
    }

    /**
     * The cart signing secret. Generated once per site and kept stable
     * across publishes so existing cart cookies stay valid.
     */
    public function cartSecret(): string
    {
        $secret = $this->secret('cart_secret');

        if ($secret === null || $secret === '') {
            $secret = bin2hex(random_bytes(32));
            $this->putSecrets(['cart_secret' => $secret]);
        }

        return $secret;
    }

    /** @return array<string, array{hash: string, size: int}> */
    public function mediaManifest(): array
    {
        $manifest = $this->media_manifest;

        return is_array($manifest) ? $manifest : [];
    }

    public function putMediaManifest(array $manifest): void
    {
        $this->forceFill(['media_manifest' => $manifest])->save();
    }

    public function previewWorkerName(): string
    {
        if ($this->preview_worker_name) {
            return $this->preview_worker_name;
        }

        return ($this->worker_name ?: 'flareweber-' . $this->id) . '--preview';
    }

    /**
     * The workers.dev URL for an environment, or null until the worker name
     * and the account's workers.dev subdomain are both known.
     */
    public function workersDevUrl(string $environment = 'production'): ?string
    {
        $name = $environment === 'preview' ? $this->previewWorkerName() : $this->worker_name;
        $subdomain = $this->cloudflareConnection?->workers_subdomain;

        if (!$name || !$subdomain) {
            return null;
        }

        return "https://{$name}.{$subdomain}.workers.dev";
    }

    /** Public https URL: the custom domain when set, else workers.dev. */
    public function liveUrl(): ?string
    {
        if ($this->domain) {
            return 'https://' . $this->domain;
        }

        return $this->workersDevUrl('production');
    }

    public function previewUrl(): ?string
    {
        return $this->workersDevUrl('preview');
    }

    public function d1DatabaseId(): ?string
    {
        $id = $this->settings['d1_database_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function stripeMethod(): ?string
    {
        $method = $this->settings['stripe_method'] ?? null;

        if (!empty($this->settings['stripe_account_id']) && $this->secret('stripe_secret_key') !== null) {
            return in_array($method, ['connect', 'key'], true) ? $method : 'connect';
        }

        return null;
    }

    public function requiresD1(): bool
    {
        return (bool) ($this->settings['forms'] ?? false) || $this->requiresEcommerce();
    }

    public function requiresR2(): bool
    {
        return (bool) ($this->settings['media'] ?? true);
    }

    public function requiresEcommerce(): bool
    {
        return (bool) ($this->settings['ecommerce'] ?? false);
    }
}
