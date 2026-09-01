<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
        'is_active',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    // ── Role Helpers ──────────────────────────────────────────

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

    // ── Relationships ─────────────────────────────────────────

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
