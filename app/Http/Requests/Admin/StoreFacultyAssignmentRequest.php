<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFacultyAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section_id' => ['required', 'exists:sections,id'],
            'faculty_id' => [
                'required',
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
                $exists = \App\Models\FacultyAssignment::where('section_id', $this->section_id)
                    ->where('faculty_id', $this->faculty_id)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('faculty_id', 'This faculty member is already assigned to this section.');
                }
            }
        });
    }
}
