<?php

namespace App\Enums;

enum QuestionType: string
{
    case Rating = 'rating';
    case YesNo = 'yes_no';
    case Text = 'text';
    case Textarea = 'textarea';
}
