<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentEnrollmentRequest extends FormRequest
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
        if (! is_scalar($this->input('section_id')) || ! is_scalar($this->input('student_id'))) {
            return true; // Let validation report malformed identifiers before model lookup.
        }
        $section = Section::with('course')->find($this->input('section_id'));
        $student = User::find($this->input('student_id'));

        return $caller->canAccessDepartment($section?->course?->department_id)
            && $caller->canAccessDepartment($student?->department_id);
    }

    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')->whereNull('deleted_at')],
            'student_id' => [
                'required',
                'integer',
                'exists:users,id',
                Rule::exists('users', 'id')->where('role', UserRole::Student->value),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'student_id.exists' => 'The selected user must exist and have the student role.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $validator->errors()->has('section_id') && ! $validator->errors()->has('student_id')) {
                $exists = StudentEnrollment::where('section_id', $this->section_id)
                    ->where('student_id', $this->student_id)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('student_id', 'This student is already enrolled in this section.');
                }
            }
        });
    }
}
