<?php

namespace App\Filament\Resources\AuditAssignmentResource\Pages;

use App\Filament\Resources\AuditAssignmentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAuditAssignments extends ListRecords
{
    protected static string $resource = AuditAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('+ Assign Audit'),
        ];
    }
}
