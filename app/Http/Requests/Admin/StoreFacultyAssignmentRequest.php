<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\FacultyAssignment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFacultyAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $caller = $this->user();
        if (! $caller?->isAdmin()) {
            return false;
        }
        if ($caller->isCentralQa()) {
            return true;
        }
        if (! is_scalar($this->input('section_id')) || ! is_scalar($this->input('faculty_id'))) {
            return true; // Let validation report malformed identifiers before model lookup.
        }
        $section = Section::with('course')->find($this->input('section_id'));
        $faculty = User::find($this->input('faculty_id'));

        return $caller->canAccessDepartment($section?->course?->department_id)
            && $caller->canAccessDepartment($faculty?->department_id);
    }

    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')->whereNull('deleted_at')],
            'faculty_id' => [
                'required',
                'integer',
                'exists:users,id',
                // Validate that the user is actually a faculty member
                Rule::exists('users', 'id')->where('role', UserRole::Faculty->value),
            ],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'faculty_id.exists' => 'The selected user must exist and have the faculty role.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $validator->errors()->has('section_id') && ! $validator->errors()->has('faculty_id')) {
                $exists = FacultyAssignment::where('section_id', $this->section_id)
                    ->where('faculty_id', $this->faculty_id)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('faculty_id', 'This faculty member is already assigned to this section.');
                }
            }
        });
    }
}
