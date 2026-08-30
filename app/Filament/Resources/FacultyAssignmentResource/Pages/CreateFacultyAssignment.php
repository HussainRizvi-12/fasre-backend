<?php

namespace App\Filament\Resources\FacultyAssignmentResource\Pages;

use App\Filament\Resources\FacultyAssignmentResource;
use App\Models\FacultyAssignment;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateFacultyAssignment extends CreateRecord
{
    protected static string $resource = FacultyAssignmentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        // Check uniqueness friendly validation
        $exists = FacultyAssignment::where('section_id', $data['section_id'])
            ->where('faculty_id', $data['faculty_id'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'faculty_id' => 'This faculty member is already assigned to this section.',
            ]);
        }

        return DB::transaction(function () use ($data) {
            if (! empty($data['is_primary'])) {
                FacultyAssignment::where('section_id', $data['section_id'])
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            return static::getModel()::create($data);
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
