<?php

namespace App\Filament\Resources\ReviewQuestionResource\Pages;

use App\Enums\FormType;
use App\Filament\Resources\ReviewQuestionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReviewQuestions extends ListRecords
{
    protected static string $resource = ReviewQuestionResource::class;

    protected ?string $subheading = 'ℹ️ These questions appear in the Student Review App for end-of-term evaluations.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('+ Add Question')
                ->mutateFormDataUsing(function (array $data): array {
                    $data['form_type'] = FormType::StudentReview;
                    return $data;
                }),
        ];
    }
}
