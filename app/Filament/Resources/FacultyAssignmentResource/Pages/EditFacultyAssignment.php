<?php

namespace App\Filament\Resources\FacultyAssignmentResource\Pages;

use App\Filament\Resources\FacultyAssignmentResource;
use App\Models\FacultyAssignment;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditFacultyAssignment extends EditRecord
{
    protected static string $resource = FacultyAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $exists = FacultyAssignment::where('section_id', $data['section_id'])
            ->where('faculty_id', $data['faculty_id'])
            ->where('id', '!=', $record->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'faculty_id' => 'This faculty member is already assigned to this section.',
            ]);
        }

        return DB::transaction(function () use ($record, $data) {
            if (! empty($data['is_primary'])) {
                FacultyAssignment::where('section_id', $data['section_id'])
                    ->where('id', '!=', $record->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $record->update($data);
            return $record;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
