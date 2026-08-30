<?php

namespace App\Filament\Resources\ReviewQuestionResource\Pages;

use App\Filament\Resources\ReviewQuestionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditReviewQuestion extends EditRecord
{
    protected static string $resource = ReviewQuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
