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
        'published_at' => 'datetime',
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
        return $this->deployments()->latest('version')->first();
    }

    public static function findByStripeAccount(string $accountId): ?self
    {
        if ($accountId === '') {
            return null;
        }

        return static::query()
            ->whereJsonIs('settings->stripe_account_id', $accountId)
            ->first();
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
