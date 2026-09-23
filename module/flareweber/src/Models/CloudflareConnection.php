<?php

namespace FlareWeber\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CloudflareConnection extends Model
{
    public const METHOD_OAUTH = 'oauth';

    public const METHOD_TOKEN = 'token';

    protected $table = 'flare_cloudflare_connections';

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
    ];

    protected $attributes = [
        'method' => self::METHOD_OAUTH,
        'status' => 'connected',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'cloudflare_connection_id');
    }

    public function isTokenMethod(): bool
    {
        return $this->method === self::METHOD_TOKEN;
    }

    public function isExpired(): bool
    {
        return $this->token_expires_at !== null
            && $this->token_expires_at->isPast();
    }

    /** Expired, or about to expire within the given number of seconds. */
    public function expiresWithin(int $seconds): bool
    {
        return $this->token_expires_at !== null
            && $this->token_expires_at->lte(now()->addSeconds($seconds));
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected'
            && $this->account_id !== null
            && $this->account_id !== ''
            && (string) $this->access_token !== '';
    }
}
