<?php

namespace App\Filament\Resources\AuditQuestionResource\Pages;

use App\Filament\Resources\AuditQuestionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAuditQuestion extends EditRecord
{
    protected static string $resource = AuditQuestionResource::class;

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
