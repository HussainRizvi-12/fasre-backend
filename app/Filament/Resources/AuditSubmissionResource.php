<?php

namespace App\Filament\Resources;

use App\Enums\AuditAssignmentStatus;
use App\Filament\Resources\AuditSubmissionResource\Pages;
use App\Models\AuditAssignment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditSubmissionResource extends Resource
{
    protected static ?string $model = AuditAssignment::class;

    protected static ?string $modelLabel = 'Audit Submission';

    protected static ?string $pluralModelLabel = 'Audit Submissions';

    protected static ?string $navigationLabel = 'Audit Submissions';

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationGroup = 'Review Cycles & Audits';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('status', [
                AuditAssignmentStatus::Submitted,
                AuditAssignmentStatus::Approved,
                AuditAssignmentStatus::Rejected,
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['auditor', 'auditee', 'section.course']))
            ->columns([
                Tables\Columns\TextColumn::make('auditee.name')
                    ->label('Auditee')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('auditor.name')
                    ->label('Auditor')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('section.name')
                    ->label('Section')
                    ->placeholder('General')
                    ->description(fn (AuditAssignment $record): ?string => $record->section?->course?->code),
                Tables\Columns\TextColumn::make('total_score')
                    ->label('Total Score')
                    ->formatStateUsing(fn ($state): string => $state ? "{$state} / 100" : '—')
                    ->badge()
                    ->color('info')
                    ->sortable(),
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
                    ->label('Submitted')
                    ->dateTime('M d, Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('decision_date')
                    ->label('Decision Date')
                    ->getStateUsing(fn (AuditAssignment $record) => $record->approved_at ?? $record->rejected_at)
                    ->dateTime('M d, Y')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        AuditAssignmentStatus::Submitted->value => 'Submitted',
                        AuditAssignmentStatus::Approved->value => 'Approved',
                        AuditAssignmentStatus::Rejected->value => 'Rejected',
                    ]),
                Tables\Filters\SelectFilter::make('section_id')
                    ->relationship('section', 'name')
                    ->label('Section'),
            ])
            ->actions([
                Tables\Actions\Action::make('view_report')
                    ->label('View Report')
                    ->icon('heroicon-o-document-text')
                    ->color('primary')
                    ->slideOver()
                    ->modalHeading(fn (AuditAssignment $record) => "Audit Report: {$record->auditee?->name}")
                    ->infolist([
                        Infolists\Components\Section::make('Audit Overview')
                            ->schema([
                                Infolists\Components\TextEntry::make('auditee.name')->label('Faculty Audited'),
                                Infolists\Components\TextEntry::make('auditor.name')->label('Auditor'),
                                Infolists\Components\TextEntry::make('section.name')->label('Section')->placeholder('General'),
                                Infolists\Components\TextEntry::make('total_score')->label('Total Score')->formatStateUsing(fn ($state) => $state ? "{$state} / 100" : 'N/A'),
                                Infolists\Components\TextEntry::make('status')->badge(),
                                Infolists\Components\TextEntry::make('submitted_at')->label('Submitted At')->dateTime('M d, Y h:i A'),
                            ])->columns(2),
                        Infolists\Components\Section::make('Admin Decision & Remarks')
                            ->schema([
                                Infolists\Components\TextEntry::make('admin_remarks')
                                    ->label('Admin Remarks')
                                    ->placeholder('No remarks provided.')
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ])
            ->emptyStateHeading('No audit submissions yet')
            ->emptyStateDescription('Completed and submitted faculty audits will appear here.')
            ->emptyStateIcon('heroicon-o-document-check');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditSubmissions::route('/'),
        ];
    }
}
