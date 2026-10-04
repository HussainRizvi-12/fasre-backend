<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'department_id',
        'is_central_qa',
        'is_active',
        'mfa_secret',
        'mfa_recovery_codes',
        'mfa_enabled_at',
        'mfa_required',
    ];

    protected $hidden = [
        'password',
        'mfa_secret',
        'mfa_recovery_codes',
    ];

    protected $appends = ['has_consented', 'enrolled_term'];

    public function getHasConsentedAttribute(): bool
    {
        return $this->consented_at !== null
            && $this->consent_version === config('fasre.student_consent_version', 'student-review-v1');
    }

    public function getEnrolledTermAttribute(): ?string
    {
        return $this->isStudent()
            ? $this->studentEnrollments()->with('section')->latest('id')->first()?->section?->term
            : null;
    }

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'is_central_qa' => 'boolean',
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'array',
            'mfa_enabled_at' => 'datetime',
            'mfa_required' => 'boolean',
            'consented_at' => 'datetime',
        ];
    }

    // ── Role & Department Scope Helpers ──────────────────────────

    public function hasMfaEnabled(): bool
    {
        return $this->mfa_enabled_at !== null && ! empty($this->mfa_secret);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isFaculty(): bool
    {
        return $this->role === UserRole::Faculty;
    }

    public function isStudent(): bool
    {
        return $this->role === UserRole::Student;
    }

    public function isCentralQa(): bool
    {
        return $this->isAdmin() && $this->is_central_qa && $this->department_id === null;
    }

    public function canAccessDepartment(?int $deptId): bool
    {
        if ($this->isCentralQa()) {
            return true;
        }

        if ($this->department_id !== null && $deptId !== null) {
            return (int) $this->department_id === (int) $deptId;
        }

        return false;
    }

    // ── Relationships ─────────────────────────────────────────

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function facultyAssignments(): HasMany
    {
        return $this->hasMany(FacultyAssignment::class, 'faculty_id');
    }

    public function studentEnrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class, 'student_id');
    }

    public function auditAssignmentsAsAuditor(): HasMany
    {
        return $this->hasMany(AuditAssignment::class, 'auditor_id');
    }

    public function auditAssignmentsAsAuditee(): HasMany
    {
        return $this->hasMany(AuditAssignment::class, 'auditee_id');
    }

    public function reviewParticipations(): HasMany
    {
        return $this->hasMany(ReviewParticipation::class, 'student_id');
    }
}
