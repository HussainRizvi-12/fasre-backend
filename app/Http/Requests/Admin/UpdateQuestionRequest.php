<?php

namespace App\Http\Requests\Admin;

use App\Enums\FormType;
use App\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'form_type' => ['sometimes', Rule::enum(FormType::class)],
            'question_text' => ['sometimes', 'string'],
            'question_type' => ['sometimes', Rule::enum(QuestionType::class)],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
