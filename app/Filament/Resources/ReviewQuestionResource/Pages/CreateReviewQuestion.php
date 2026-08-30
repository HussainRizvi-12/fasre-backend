<?php

namespace App\Filament\Resources\ReviewQuestionResource\Pages;

use App\Enums\FormType;
use App\Filament\Resources\ReviewQuestionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReviewQuestion extends CreateRecord
{
    protected static string $resource = ReviewQuestionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['form_type'] = FormType::StudentReview;
        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
