<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvidenceFile extends Model
{
    protected $fillable = [
        'audit_assignment_id',
        'question_id',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function auditAssignment(): BelongsTo
    {
        return $this->belongsTo(AuditAssignment::class);
    }
}
