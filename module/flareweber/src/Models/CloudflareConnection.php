<?php

namespace FlareWeber\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CloudflareConnection extends Model
{
    protected $table = 'flare_cloudflare_connections';

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'cloudflare_connection_id');
    }

    public function isExpired(): bool
    {
        return $this->token_expires_at !== null
            && $this->token_expires_at->isPast();
    }
}
