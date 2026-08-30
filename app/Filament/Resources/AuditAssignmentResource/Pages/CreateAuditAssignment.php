<?php

namespace App\Filament\Resources\AuditAssignmentResource\Pages;

use App\Enums\AuditAssignmentStatus;
use App\Filament\Resources\AuditAssignmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAuditAssignment extends CreateRecord
{
    protected static string $resource = AuditAssignmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['assigned_by'] = auth()->id();
        $data['status'] = AuditAssignmentStatus::Assigned;
        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
