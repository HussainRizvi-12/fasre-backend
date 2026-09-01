<?php

namespace App\Filament\Resources\StudentEnrollmentResource\Pages;

use App\Filament\Resources\StudentEnrollmentResource;
use App\Models\StudentEnrollment;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStudentEnrollment extends CreateRecord
{
    protected static string $resource = StudentEnrollmentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $sectionId = $data['section_id'];
        $studentIds = (array) ($data['student_ids'] ?? []);

        $lastRecord = null;
        $createdCount = 0;
        $skippedCount = 0;

        foreach ($studentIds as $studentId) {
            $exists = StudentEnrollment::where('section_id', $sectionId)
                ->where('student_id', $studentId)
                ->exists();

            if (! $exists) {
                $lastRecord = StudentEnrollment::create([
                    'section_id' => $sectionId,
                    'student_id' => $studentId,
                ]);
                $createdCount++;
            } else {
                $skippedCount++;
            }
        }

        if ($createdCount === 0 && $skippedCount > 0) {
            Notification::make()
                ->title('Selected student(s) already enrolled in this section.')
                ->warning()
                ->send();
        } elseif ($skippedCount > 0) {
            Notification::make()
                ->title("Enrolled {$createdCount} student(s). {$skippedCount} duplicate(s) were skipped.")
                ->info()
                ->send();
        }

        return $lastRecord ?? StudentEnrollment::where('section_id', $sectionId)->firstOrFail();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
