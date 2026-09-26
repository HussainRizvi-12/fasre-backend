<?php

namespace App\Models;

use App\Enums\AuditAssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Peer Classroom Observation / Audit Assignment.
 *
 * NOTE: auditor_id must NOT equal auditee_id.
 * This constraint is enforced at the Form Request / application layer.
 */
class AuditAssignment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'auditor_id',
        'auditee_id',
        'section_id',
        'form_version_id',
        'assigned_by',
        'status',
        'due_date',
        'observation_date',
        'observation_context',
        'conflict_declared',
        'revision_number',
        'answers_json',
        'comments_json',
        'previous_version_answers_json',
        'previous_version_comments_json',
        'total_score',
        'outcome_band',
        'admin_remarks',
        'rejection_reason',
        'faculty_response',
        'faculty_responded_at',
        'is_legacy_assignment',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'auditor_id' => 'integer',
            'auditee_id' => 'integer',
            'section_id' => 'integer',
            'assigned_by' => 'integer',
            'closed_by' => 'integer',
            'status' => AuditAssignmentStatus::class,
            'due_date' => 'date',
            'observation_date' => 'date',
            'conflict_declared' => 'boolean',
            'is_legacy_assignment' => 'boolean',
            'revision_number' => 'integer',
            'answers_json' => 'array',
            'comments_json' => 'array',
            'previous_version_answers_json' => 'array',
            'previous_version_comments_json' => 'array',
            'total_score' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'faculty_responded_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function evidenceFiles(): HasMany
    {
        return $this->hasMany(AuditEvidenceFile::class);
    }

    public function provenanceLogs(): MorphMany
    {
        return $this->morphMany(AuditProvenanceLog::class, 'auditable');
    }

    public function improvementActions(): HasMany
    {
        return $this->hasMany(AuditImprovementAction::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Checks if the audit is in an editable draft state for the auditor.
     * All post-submission/finalized states (submitted, approved, faculty_responded, action_plan_active, closed)
     * are strictly immutable.
     */
    public function isEditableByAuditor(): bool
    {
        return in_array($this->status, [
            AuditAssignmentStatus::Assigned,
            AuditAssignmentStatus::InProgress,
            AuditAssignmentStatus::Rejected,
        ], true);
    }
}
