<?php

namespace App\Http\Requests\Admin;

use App\Enums\AuditAssignmentStatus;
use App\Enums\UserRole;
use App\Models\AuditAssignment;
use App\Models\FacultyAssignment;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAuditAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'auditor_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Faculty->value)->where('is_active', true),
            ],
            'auditee_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Faculty->value)->where('is_active', true),
            ],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'form_version_id' => [
                'nullable',
                'integer',
                Rule::exists('form_versions', 'id')
                    ->where('form_type', \App\Enums\FormType::FacultyAudit->value)
                    ->where('is_published', true),
            ],
            'observation_date' => ['nullable', 'date'],
            'observation_context' => ['nullable', 'string', 'max:1000'],
            'conflict_declared' => ['nullable', 'boolean'],
            'due_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'auditor_id.exists' => 'The selected auditor must exist, be active, and have the faculty role.',
            'auditee_id.exists' => 'The selected auditee must exist, be active, and have the faculty role.',
            'section_id.exists' => 'The selected course section does not exist.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $auditorId = (int) $this->input('auditor_id');
            $auditeeId = (int) $this->input('auditee_id');
            $sectionId = $this->filled('section_id') ? (int) $this->input('section_id') : null;

            if ($validator->errors()->has('auditor_id') || $validator->errors()->has('auditee_id')) {
                return;
            }

            // Conflict of interest: Self-audit prevention
            if ($auditorId === $auditeeId) {
                $validator->errors()->add('auditee_id', 'Auditor and auditee must be different faculty members.');
                return;
            }

            // Academic validation: auditee must actually teach the section
            if ($sectionId !== null) {
                $teachesSection = FacultyAssignment::where('section_id', $sectionId)
                    ->where('faculty_id', $auditeeId)
                    ->exists();

                if (! $teachesSection) {
                    $validator->errors()->add('section_id', 'The selected auditee is not assigned to teach this section.');
                    return;
                }
            }

            // Scoped duplicate observation check (allows separate observations of different sections)
            $query = AuditAssignment::where('auditor_id', $auditorId)
                ->where('auditee_id', $auditeeId)
                ->whereIn('status', [
                    AuditAssignmentStatus::Assigned,
                    AuditAssignmentStatus::InProgress,
                    AuditAssignmentStatus::Submitted,
                ]);

            if ($sectionId !== null) {
                $query->where('section_id', $sectionId);
            } else {
                $query->whereNull('section_id');
            }

            if ($query->exists()) {
                $validator->errors()->add('auditee_id', 'An active peer audit assignment already exists for this auditor, auditee, and section.');
            }
        });
    }
}
