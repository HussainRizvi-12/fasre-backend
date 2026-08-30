<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['sometimes', 'exists:departments,id'],
            'code' => ['sometimes', 'string', 'max:50'],
            'title' => ['sometimes', 'string', 'max:255'],
            'credit_hours' => ['nullable', 'integer', 'min:1', 'max:12'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
