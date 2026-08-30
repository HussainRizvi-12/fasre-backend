<?php

namespace App\Filament\Resources;

use App\Enums\ReviewWindowStatus;
use App\Filament\Pages\ReviewResults;
use App\Filament\Resources\ReviewWindowResource\Pages;
use App\Models\ReviewWindow;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ReviewWindowResource extends Resource
{
    protected static ?string $model = ReviewWindow::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Review Cycles & Audits';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Card::make()
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Fall 2026 Student Reviews')
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('description')
                            ->rows(3)
                            ->placeholder('Evaluation window instructions or details...')
                            ->columnSpanFull(),
                        Forms\Components\DateTimePicker::make('starts_at')
                            ->label('Start Date & Time')
                            ->required(),
                        Forms\Components\DateTimePicker::make('ends_at')
                            ->label('End Date & Time')
                            ->required()
                            ->after('starts_at'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Window Title')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (ReviewWindow $record): ?string => $record->description),
                Tables\Columns\TextColumn::make('date_range')
                    ->label('Date Range')
                    ->getStateUsing(fn (ReviewWindow $record): string => 
                        $record->starts_at->format('M d, Y') . ' — ' . $record->ends_at->format('M d, Y')
                    )
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ucfirst($state instanceof ReviewWindowStatus ? $state->value : (string) $state))
                    ->color(fn ($state): string => match ($state instanceof ReviewWindowStatus ? $state->value : (string) $state) {
                        'draft' => 'gray',
                        'active' => 'success',
                        'closed' => 'warning',
                        'published' => 'primary',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        ReviewWindowStatus::Draft->value => 'Draft',
                        ReviewWindowStatus::Active->value => 'Active',
                        ReviewWindowStatus::Closed->value => 'Closed',
                        ReviewWindowStatus::Published->value => 'Published',
                    ]),
            ])
            ->actions([
                // State Machine: Draft -> Active
                Tables\Actions\Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (ReviewWindow $record): bool => $record->status === ReviewWindowStatus::Draft)
                    ->requiresConfirmation()
                    ->modalHeading('Activate Review Window')
                    ->modalDescription('Activate this review window? Students will be able to submit reviews immediately.')
                    ->action(function (ReviewWindow $record): void {
                        $hasOtherActive = ReviewWindow::where('status', ReviewWindowStatus::Active)
                            ->where('id', '!=', $record->id)
                            ->exists();

                        if ($hasOtherActive) {
                            Notification::make()
                                ->title('Cannot activate review window')
                                ->body('Another review window is currently active. Please close it first before activating a new one.')
                                ->danger()
                                ->send();
                            return;
                        }

                        $record->update(['status' => ReviewWindowStatus::Active]);
                        Notification::make()
                            ->title('Review window activated successfully')
                            ->success()
                            ->send();
                    }),

                // State Machine: Active -> Closed
                Tables\Actions\Action::make('close')
                    ->label('Close Window')
                    ->icon('heroicon-o-stop')
                    ->color('warning')
                    ->visible(fn (ReviewWindow $record): bool => $record->status === ReviewWindowStatus::Active)
                    ->requiresConfirmation()
                    ->modalHeading('Close Review Window')
                    ->modalDescription('Close this review window? No further review submissions will be accepted.')
                    ->action(function (ReviewWindow $record): void {
                        $record->update(['status' => ReviewWindowStatus::Closed]);
                        Notification::make()
                            ->title('Review window closed successfully')
                            ->warning()
                            ->send();
                    }),

                // State Machine: Closed -> Published
                Tables\Actions\Action::make('publish')
                    ->label('Publish Results')
                    ->icon('heroicon-o-arrow-up-on-square')
                    ->color('primary')
                    ->visible(fn (ReviewWindow $record): bool => $record->status === ReviewWindowStatus::Closed)
                    ->requiresConfirmation()
                    ->modalHeading('Publish Review Results')
                    ->modalDescription('Publish results for this review window? Aggregated results will be finalized and viewable.')
                    ->action(function (ReviewWindow $record): void {
                        $record->update(['status' => ReviewWindowStatus::Published]);
                        Notification::make()
                            ->title('Results published successfully')
                            ->success()
                            ->send();
                    }),

                // State: Published -> View Results
                Tables\Actions\Action::make('view_results')
                    ->label('View Results')
                    ->icon('heroicon-o-chart-bar')
                    ->color('primary')
                    ->visible(fn (ReviewWindow $record): bool => $record->status === ReviewWindowStatus::Published)
                    ->url(fn (ReviewWindow $record): string => ReviewResults::getUrl(['review_window_id' => $record->id])),

                Tables\Actions\EditAction::make()
                    ->visible(fn (ReviewWindow $record): bool => $record->status === ReviewWindowStatus::Draft),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (ReviewWindow $record): bool => $record->status === ReviewWindowStatus::Draft),
            ])
            ->emptyStateHeading('No review windows yet')
            ->emptyStateDescription('Create a review window to start student evaluations.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReviewWindows::route('/'),
            'create' => Pages\CreateReviewWindow::route('/create'),
            'edit' => Pages\EditReviewWindow::route('/{record}/edit'),
        ];
    }
}
