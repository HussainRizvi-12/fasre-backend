<?php

namespace App\Filament\Resources\ReviewWindowResource\Pages;

use App\Filament\Resources\ReviewWindowResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReviewWindows extends ListRecords
{
    protected static string $resource = ReviewWindowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('+ Create Review Window'),
        ];
    }
}
