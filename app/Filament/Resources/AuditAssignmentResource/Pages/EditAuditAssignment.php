<?php

namespace App\Filament\Resources\AuditAssignmentResource\Pages;

use App\Filament\Resources\AuditAssignmentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAuditAssignment extends EditRecord
{
    protected static string $resource = AuditAssignmentResource::class;

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
