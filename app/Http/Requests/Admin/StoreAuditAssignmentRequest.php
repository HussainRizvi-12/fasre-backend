<?php

namespace App\Http\Requests\Admin;

use App\Enums\AuditAssignmentStatus;
use App\Enums\UserRole;
use App\Models\AuditAssignment;
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
                Rule::exists('users', 'id')->where('role', UserRole::Faculty->value),
            ],
            'auditee_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Faculty->value),
            ],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'due_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'auditor_id.exists' => 'The selected auditor must exist and have the faculty role.',
            'auditee_id.exists' => 'The selected auditee must exist and have the faculty role.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->has('auditor_id') || $validator->errors()->has('auditee_id')) {
                return;
            }

            if ((int) $this->input('auditor_id') === (int) $this->input('auditee_id')) {
                $validator->errors()->add('auditee_id', 'Auditor and auditee must be different faculty members.');

                return;
            }

            $duplicate = AuditAssignment::where('auditor_id', $this->input('auditor_id'))
                ->where('auditee_id', $this->input('auditee_id'))
                ->whereIn('status', [
                    AuditAssignmentStatus::Assigned,
                    AuditAssignmentStatus::InProgress,
                    AuditAssignmentStatus::Submitted,
                ])
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('auditee_id', 'This auditor already has an active audit for this auditee.');
            }
        });
    }
}
