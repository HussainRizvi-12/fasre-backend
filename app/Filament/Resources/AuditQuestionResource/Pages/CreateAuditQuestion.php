<?php

namespace App\Filament\Resources\AuditQuestionResource\Pages;

use App\Enums\FormType;
use App\Filament\Resources\AuditQuestionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAuditQuestion extends CreateRecord
{
    protected static string $resource = AuditQuestionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['form_type'] = FormType::FacultyAudit;
        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
