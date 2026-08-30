<?php

namespace App\Models;

use App\Enums\AuditAssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NOTE: auditor_id must NOT equal auditee_id.
 * This constraint is enforced at the Form Request / application layer,
 * not at the database level. Any future Form Request for creating or
 * updating audit assignments must validate: 'auditor_id' !== 'auditee_id'.
 */
class AuditAssignment extends Model
{
    protected $fillable = [
        'auditor_id',
        'auditee_id',
        'section_id',
        'assigned_by',
        'status',
        'due_date',
        'answers_json',
        'total_score',
        'admin_remarks',
        'submitted_at',
        'approved_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AuditAssignmentStatus::class,
            'due_date' => 'date',
            'answers_json' => 'array',
            'total_score' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }

    public function auditee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auditee_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
