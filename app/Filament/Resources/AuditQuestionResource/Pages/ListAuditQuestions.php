<?php

namespace App\Filament\Resources\AuditQuestionResource\Pages;

use App\Enums\FormType;
use App\Filament\Resources\AuditQuestionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAuditQuestions extends ListRecords
{
    protected static string $resource = AuditQuestionResource::class;

    protected ?string $subheading = 'ℹ️ These questions appear in the Faculty Audit App for peer teaching evaluations.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('+ Add Question')
                ->mutateFormDataUsing(function (array $data): array {
                    $data['form_type'] = FormType::FacultyAudit;
                    return $data;
                }),
        ];
    }
}
