<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Section extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'course_id',
        'name',
        'term',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function facultyAssignments(): HasMany
    {
        return $this->hasMany(FacultyAssignment::class);
    }

    public function studentEnrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    public function participations(): HasMany
    {
        return $this->hasMany(ReviewParticipation::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(ReviewResponse::class);
    }

    public function auditAssignments(): HasMany
    {
        return $this->hasMany(AuditAssignment::class);
    }

    /**
     * Checks if this section has official historical evaluation or audit records.
     */
    public function hasEvaluations(): bool
    {
        return $this->participations()->exists()
            || $this->responses()->exists()
            || $this->auditAssignments()->exists();
    }
}
