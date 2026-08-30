<?php

namespace App\Filament\Widgets;

use App\Enums\AuditAssignmentStatus;
use App\Filament\Resources\AuditSubmissionResource;
use App\Models\AuditAssignment;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentAuditSubmissionsWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    protected static ?string $heading = 'Recent Audit Submissions';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AuditAssignment::query()
                    ->with(['auditor', 'auditee', 'section.course'])
                    ->whereIn('status', [
                        AuditAssignmentStatus::Submitted,
                        AuditAssignmentStatus::Approved,
                        AuditAssignmentStatus::Rejected,
                    ])
                    ->latest('submitted_at')
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('auditee.name')
                    ->label('Auditee')
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('auditor.name')
                    ->label('Auditor'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ($state instanceof AuditAssignmentStatus ? $state->value : (string) $state) {
                        'submitted' => 'Submitted',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        default => ucfirst((string) $state),
                    })
                    ->color(fn ($state): string => match ($state instanceof AuditAssignmentStatus ? $state->value : (string) $state) {
                        'submitted' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Date')
                    ->dateTime('M d')
                    ->placeholder('—'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('view_all')
                    ->label('View All')
                    ->icon('heroicon-m-arrow-right')
                    ->url(AuditSubmissionResource::getUrl('index')),
            ])
            ->paginated(false)
            ->emptyStateHeading('No submissions yet')
            ->emptyStateDescription('Completed audits will appear here.');
    }
}
