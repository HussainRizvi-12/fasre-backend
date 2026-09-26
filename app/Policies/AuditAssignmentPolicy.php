<?php

namespace App\Policies;

use App\Enums\AuditAssignmentStatus;
use App\Models\AuditAssignment;
use App\Models\User;

class AuditAssignmentPolicy
{
    /**
     * Determine whether the user can view any audit assignments.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isFaculty();
    }

    /**
     * Determine whether the user can view the specific audit assignment.
     */
    public function view(User $user, AuditAssignment $audit): bool
    {
        if ($user->isAdmin()) {
            return $user->canAccessDepartment($audit->section?->course?->department_id);
        }

        if ($user->isFaculty()) {
            if ($audit->auditor_id === $user->id) {
                return true;
            }

            if ($audit->auditee_id === $user->id && in_array($audit->status, [
                AuditAssignmentStatus::Approved,
                AuditAssignmentStatus::FacultyResponded,
                AuditAssignmentStatus::ActionPlanActive,
                AuditAssignmentStatus::Closed,
            ], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the user can create an audit assignment.
     */
    public function create(User $user, ?int $departmentId = null): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        return $user->canAccessDepartment($departmentId);
    }

    /**
     * Determine whether the user can approve or reject the audit assignment.
     */
    public function decide(User $user, AuditAssignment $audit): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        return $user->canAccessDepartment($audit->section?->course?->department_id);
    }

    /**
     * Determine whether the faculty auditee can submit a response to an approved report.
     */
    public function respond(User $user, AuditAssignment $audit): bool
    {
        return $user->isFaculty()
            && $audit->auditee_id === $user->id
            && in_array($audit->status, [
                AuditAssignmentStatus::Approved,
                AuditAssignmentStatus::FacultyResponded,
                AuditAssignmentStatus::ActionPlanActive,
            ], true);
    }
}
