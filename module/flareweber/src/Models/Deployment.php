<?php

namespace FlareWeber\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    /** Step keys in pipeline order, with their human labels. */
    public const STEPS = [
        'validate' => 'Validate',
        'provision' => 'Provision resources',
        'media' => 'Sync media',
        'compile' => 'Compile site',
        'upload' => 'Upload worker',
        'secrets' => 'Configure secrets',
        'seed' => 'Seed database',
        'health' => 'Health check',
        'domain' => 'Custom domain',
    ];

    protected $table = 'flare_deployments';

    protected $guarded = ['id'];

    protected $casts = [
        'steps' => 'array',
        'finished_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function rollbackTarget(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rollback_to');
    }

    public function isRollback(): bool
    {
        return $this->rollback_to !== null;
    }

    /** @return array<int, array{key: string, label: string, status: string, detail: string|null}> */
    public static function initialSteps(): array
    {
        $steps = [];

        foreach (self::STEPS as $key => $label) {
            $steps[] = ['key' => $key, 'label' => $label, 'status' => 'pending', 'detail' => null];
        }

        return $steps;
    }

    /** @return array<int, array{key: string, label: string, status: string, detail: string|null}> */
    public function stepList(): array
    {
        $steps = $this->steps;

        return is_array($steps) && $steps !== [] ? $steps : self::initialSteps();
    }

    /**
     * Update one step's status (pending|running|done|failed|skipped) and
     * persist the step list immediately so pollers see progress.
     */
    public function setStep(string $key, string $status, ?string $detail = null): void
    {
        $steps = $this->stepList();
        $found = false;

        foreach ($steps as &$step) {
            if ($step['key'] === $key) {
                $step['status'] = $status;
                if ($detail !== null) {
                    $step['detail'] = $detail;
                }
                $found = true;
                break;
            }
        }
        unset($step);

        if (!$found) {
            $steps[] = [
                'key' => $key,
                'label' => self::STEPS[$key] ?? ucfirst($key),
                'status' => $status,
                'detail' => $detail,
            ];
        }

        $this->steps = $steps;
        $this->save();
    }

    /** Mark every step still pending or running as the given status. */
    public function finishRemainingSteps(string $status, ?string $detail = null): void
    {
        $steps = $this->stepList();

        foreach ($steps as &$step) {
            if (in_array($step['status'], ['pending', 'running'], true)) {
                $step['status'] = $status;
                if ($detail !== null && $step['status'] !== 'skipped') {
                    $step['detail'] = $detail;
                }
            }
        }
        unset($step);

        $this->steps = $steps;
        $this->save();
    }

    public function appendLog(string $line): void
    {
        $this->log = ($this->log ?? '') . date('[H:i:s] ') . $line . "\n";
        $this->save();
    }
}
