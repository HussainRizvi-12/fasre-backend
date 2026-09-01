<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Lightweight internal activity logger (no external package dependency).
 */
class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function log(
        ?Model $subject,
        string $action,
        array $properties = [],
    ): void {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'properties' => $properties,
        ]);
    }
}
