<?php

namespace App\Filament\Resources\FacultyAssignmentResource\Pages;

use App\Filament\Resources\FacultyAssignmentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFacultyAssignments extends ListRecords
{
    protected static string $resource = FacultyAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('+ Assign Faculty'),
        ];
    }
}
