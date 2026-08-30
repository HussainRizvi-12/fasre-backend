<?php

namespace App\Http\Requests\Admin;

use App\Enums\FormType;
use App\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'form_type' => ['required', Rule::enum(FormType::class)],
            'question_text' => ['required', 'string'],
            'question_type' => ['required', Rule::enum(QuestionType::class)],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
