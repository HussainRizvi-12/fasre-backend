<?php

namespace App\Filament\Widgets;

use App\Enums\ReviewWindowStatus;
use App\Filament\Resources\ReviewWindowResource;
use App\Models\ReviewWindow;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ActiveReviewWindowsWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    protected static ?string $heading = 'Active Review Windows';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ReviewWindow::query()
                    ->where('status', ReviewWindowStatus::Active)
                    ->orderBy('ends_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Window Title')
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('date_range')
                    ->label('Timeline')
                    ->getStateUsing(fn (ReviewWindow $record): string => 
                        $record->starts_at->format('M d') . ' – ' . $record->ends_at->format('M d, Y')
                    )
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (): string => 'Active')
                    ->color('success'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('manage')
                    ->label('Manage')
                    ->icon('heroicon-m-arrow-right')
                    ->url(ReviewWindowResource::getUrl('index')),
            ])
            ->paginated(false)
            ->emptyStateHeading('No active windows')
            ->emptyStateDescription('Active student review windows will appear here.');
    }
}
