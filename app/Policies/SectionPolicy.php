<?php

namespace App\Policies;

use App\Models\Section;
use App\Models\User;

class SectionPolicy
{
    public function view(User $user, Section $section): bool
    {
        if ($user->isAdmin()) {
            return $user->canAccessDepartment($section->course?->department_id);
        }

        if ($user->isFaculty()) {
            return $section->facultyAssignments()->where('faculty_id', $user->id)->exists();
        }

        if ($user->isStudent()) {
            return $section->studentEnrollments()->where('student_id', $user->id)->exists();
        }

        return false;
    }

    public function manage(User $user, Section $section): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        return $user->canAccessDepartment($section->course?->department_id);
    }
}
