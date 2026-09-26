<?php

namespace App\Models;

use App\Enums\ReviewWindowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReviewWindow extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'term',
        'department_id',
        'form_version_id',
        'description',
        'starts_at',
        'ends_at',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'status' => ReviewWindowStatus::class,
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }

    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(Section::class, 'review_window_sections');
    }

    public function rosterEntries(): HasMany
    {
        return $this->hasMany(ReviewWindowRoster::class);
    }

    public function participations(): HasMany
    {
        return $this->hasMany(ReviewParticipation::class, 'review_window_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(ReviewResponse::class, 'review_window_id');
    }

    public function provenanceLogs(): MorphMany
    {
        return $this->morphMany(AuditProvenanceLog::class, 'auditable');
    }

    /**
     * Determines whether a given section is within the academic scope of this cycle.
     */
    public function isSectionEligible(int $sectionId): bool
    {
        $section = Section::with('course')->find($sectionId);
        if (! $section || ! $section->is_active) {
            return false;
        }

        // 1. If explicit sections are linked, require membership
        if ($this->sections()->exists()) {
            if (! $this->sections()->where('sections.id', $sectionId)->exists()) {
                return false;
            }
        }

        // 2. If term is specified, match section term
        if ($this->term && $section->term !== $this->term) {
            return false;
        }

        // 3. If department is specified, match section course department
        if ($this->department_id && (int) $section->course?->department_id !== (int) $this->department_id) {
            return false;
        }

        return true;
    }

    /**
     * Snapshots the eligible student roster at cycle opening.
     */
    public function snapshotRoster(): int
    {
        $eligibleSectionQuery = Section::where('is_active', true);
        if ($this->sections()->exists()) {
            $eligibleSectionIds = $this->sections()->pluck('sections.id')->all();
            $eligibleSectionQuery->whereIn('id', $eligibleSectionIds);
        } else {
            if ($this->term) {
                $eligibleSectionQuery->where('term', $this->term);
            }
            if ($this->department_id) {
                $eligibleSectionQuery->whereHas('course', fn ($q) => $q->where('department_id', $this->department_id));
            }
        }

        $sectionIds = $eligibleSectionQuery->pluck('id')->all();
        $enrollments = StudentEnrollment::whereIn('section_id', $sectionIds)
            ->whereHas('student', fn ($q) => $q->where('is_active', true))
            ->get();

        $count = 0;
        foreach ($enrollments as $enrollment) {
            ReviewWindowRoster::firstOrCreate([
                'review_window_id' => $this->id,
                'section_id' => $enrollment->section_id,
                'student_id' => $enrollment->student_id,
            ], [
                'status' => 'eligible',
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Verifies if a student is eligible to submit for a section in this cycle.
     */
    public function isStudentEligible(int $studentId, int $sectionId): bool
    {
        if (! $this->isSectionEligible($sectionId)) {
            return false;
        }

        // Check frozen roster if present
        if ($this->rosterEntries()->exists()) {
            return $this->rosterEntries()
                ->where('section_id', $sectionId)
                ->where('student_id', $studentId)
                ->where('status', 'eligible')
                ->exists();
        }

        // Fallback to active enrollment
        return StudentEnrollment::where('section_id', $sectionId)
            ->where('student_id', $studentId)
            ->exists();
    }

    public function isActive(): bool
    {
        return $this->status === ReviewWindowStatus::Active;
    }

    public function roster(): HasMany
    {
        return $this->rosterEntries();
    }
}
