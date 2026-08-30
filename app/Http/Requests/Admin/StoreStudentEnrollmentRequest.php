<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section_id' => ['required', 'exists:sections,id'],
            'student_id' => [
                'required',
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
                $exists = \App\Models\StudentEnrollment::where('section_id', $this->section_id)
                    ->where('student_id', $this->student_id)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('student_id', 'This student is already enrolled in this section.');
                }
            }
        });
    }
}
