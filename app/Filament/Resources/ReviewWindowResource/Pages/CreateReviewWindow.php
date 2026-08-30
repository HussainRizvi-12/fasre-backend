<?php

namespace App\Filament\Resources\ReviewWindowResource\Pages;

use App\Enums\ReviewWindowStatus;
use App\Filament\Resources\ReviewWindowResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReviewWindow extends CreateRecord
{
    protected static string $resource = ReviewWindowResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = ReviewWindowStatus::Draft;
        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
