<?php

namespace App\Models;

use App\Enums\FormType;
use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $fillable = [
        'form_type',
        'question_text',
        'question_type',
        'is_required',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'form_type' => FormType::class,
            'question_type' => QuestionType::class,
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
