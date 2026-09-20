<?php

namespace FlareWeber\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    protected $table = 'flare_deployments';

    protected $guarded = ['id'];

    protected $casts = [
        'finished_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function appendLog(string $line): void
    {
        $this->log = ($this->log ?? '') . date('[H:i:s] ') . $line . "\n";
        $this->save();
    }
}
