<?php

namespace App\Filament\Resources\ReviewWindowResource\Pages;

use App\Enums\ReviewWindowStatus;
use App\Filament\Resources\ReviewWindowResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditReviewWindow extends EditRecord
{
    protected static string $resource = ReviewWindowResource::class;

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        if ($this->getRecord()->status !== ReviewWindowStatus::Draft) {
            Notification::make()
                ->title('Cannot edit review window once activated.')
                ->danger()
                ->send();

            $this->redirect(ReviewWindowResource::getUrl('index'));
        }
    }

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
