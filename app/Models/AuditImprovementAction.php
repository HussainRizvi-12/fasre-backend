<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditImprovementAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'audit_assignment_id',
        'finding',
        'agreed_action',
        'owner_id',
        'due_date',
        'status',
        'follow_up_note',
        'closed_by',
        'closed_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'closed_at' => 'datetime',
    ];

    public function auditAssignment(): BelongsTo
    {
        return $this->belongsTo(AuditAssignment::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}
