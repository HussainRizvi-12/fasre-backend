<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditProvenanceLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'action',
        'actor_id',
        'reason',
        'before_state_json',
        'after_state_json',
        'ip_address',
    ];

    protected $casts = [
        'before_state_json' => 'array',
        'after_state_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Sanitizes state arrays to ensure student answer payloads,
     * passwords, and tokens are NEVER persisted in provenance logs.
     */
    public static function sanitizeState(?array $state): ?array
    {
        if ($state === null) {
            return null;
        }

        $redactedKeys = [
            'password',
            'remember_token',
            'token',
            'answers',
            'answers_json',
            'comments_json',
            'pseudonym_token',
        ];

        $sanitized = [];
        foreach ($state as $key => $val) {
            if (in_array(strtolower($key), $redactedKeys, true)) {
                $sanitized[$key] = '[REDACTED_PAYLOAD]';
            } elseif (is_array($val)) {
                $sanitized[$key] = self::sanitizeState($val);
            } else {
                $sanitized[$key] = $val;
            }
        }

        return $sanitized;
    }

    /**
     * Records an attributable, append-only provenance event.
     */
    public static function record(
        Model $auditable,
        string $action,
        ?User $actor = null,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null,
        ?string $ip = null
    ): self {
        return self::create([
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'action' => $action,
            'actor_id' => $actor?->id,
            'reason' => $reason,
            'before_state_json' => self::sanitizeState($before),
            'after_state_json' => self::sanitizeState($after),
            'ip_address' => $ip,
        ]);
    }
}
