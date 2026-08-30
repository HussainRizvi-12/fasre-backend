<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IMPORTANT: This model deliberately has NO student_id or user_id relationship.
 * Anonymity is enforced by table isolation — this table stores only a pseudonym_token
 * with no link back to the submitting student's identity.
 *
 * ANONYMITY TIMESTAMP MITIGATION:
 * 1. Automatic timestamps are disabled ($timestamps = false) and created_at/updated_at
 *    columns are dropped from the database schema entirely.
 * 2. submitted_at is stored at coarse date-level granularity (startOfDay) to prevent
 *    sub-day timestamp correlation attacks against review_participations.
 */
class ReviewResponse extends Model
{
    /**
     * Disable Eloquent automatic timestamps so created_at/updated_at
     * are never written or managed on this table.
     */
    public $timestamps = false;

    protected $fillable = [
        'review_window_id',
        'section_id',
        'pseudonym_token',
        'answers_json',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'answers_json' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function reviewWindow(): BelongsTo
    {
        return $this->belongsTo(ReviewWindow::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
