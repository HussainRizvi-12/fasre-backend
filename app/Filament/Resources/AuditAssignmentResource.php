<?php

namespace App\Filament\Resources;

use App\Enums\AuditAssignmentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\AuditAssignmentResource\Pages;
use App\Models\AuditAssignment;
use App\Models\Question;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditAssignmentResource extends Resource
{
    protected static ?string $model = AuditAssignment::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Reviews & Audits';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Card::make()
                    ->schema([
                        Forms\Components\Select::make('auditor_id')
                            ->label('Auditor (Faculty Member)')
                            ->options(fn () => User::where('role', UserRole::Faculty)->where('is_active', true)->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live(),

                        Forms\Components\Select::make('auditee_id')
                            ->label('Auditee (Faculty to be Audited)')
                            ->options(fn () => User::where('role', UserRole::Faculty)->where('is_active', true)->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->different('auditor_id')
                            ->validationMessages([
                                'different' => 'Auditor and Auditee must be different faculty members.',
                            ]),

                        Forms\Components\Select::make('section_id')
                            ->relationship('section', 'name')
                            ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->name} — {$record->course?->code} ({$record->term})")
                            ->searchable()
                            ->preload()
                            ->placeholder('Optional: Select a course section'),

                        Forms\Components\DatePicker::make('due_date')
                            ->label('Due Date')
                            ->placeholder('Select target completion date'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['auditor', 'auditee', 'section.course']))
            ->columns([
                Tables\Columns\TextColumn::make('auditor.name')
                    ->label('Auditor')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('auditee.name')
                    ->label('Auditee')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('section.name')
                    ->label('Section')
                    ->placeholder('General')
                    ->description(fn (AuditAssignment $record): ?string => $record->section?->course?->code),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ($state instanceof AuditAssignmentStatus ? $state->value : (string) $state) {
                        'assigned' => 'Assigned',
                        'in_progress' => 'In Progress',
                        'submitted' => 'Submitted',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        default => ucfirst((string) $state),
                    })
                    ->color(fn ($state): string => match ($state instanceof AuditAssignmentStatus ? $state->value : (string) $state) {
                        'assigned' => 'gray',
                        'in_progress' => 'info',
                        'submitted' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date('M d, Y')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime('M d, Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        AuditAssignmentStatus::Assigned->value => 'Assigned',
                        AuditAssignmentStatus::InProgress->value => 'In Progress',
                        AuditAssignmentStatus::Submitted->value => 'Submitted',
                        AuditAssignmentStatus::Approved->value => 'Approved',
                        AuditAssignmentStatus::Rejected->value => 'Rejected',
                    ]),
                Tables\Filters\SelectFilter::make('auditor_id')
                    ->relationship('auditor', 'name')
                    ->label('Auditor'),
                Tables\Filters\SelectFilter::make('auditee_id')
                    ->relationship('auditee', 'name')
                    ->label('Auditee'),
            ])
            ->actions([
                // Review Action for 'submitted' audits
                Tables\Actions\Action::make('review')
                    ->label('Review Audit')
                    ->icon('heroicon-o-eye')
                    ->color('warning')
                    ->slideOver()
                    ->modalHeading(fn (AuditAssignment $record) => "Review Audit: {$record->auditee?->name}")
                    ->modalDescription(fn (AuditAssignment $record) => "Audited by {$record->auditor?->name} on " . ($record->submitted_at?->format('M d, Y') ?? 'N/A'))
                    ->visible(fn (AuditAssignment $record): bool => $record->status === AuditAssignmentStatus::Submitted)
                    ->form([
                        Forms\Components\Placeholder::make('score_display')
                            ->label('Computed Total Score')
                            ->content(fn (AuditAssignment $record) => ($record->total_score ?? 'N/A') . ' / 100'),
                        Forms\Components\Textarea::make('admin_remarks')
                            ->label('Admin Feedback / Remarks')
                            ->placeholder('Enter remarks or reason for decision...')
                            ->rows(3),
                    ])
                    ->modalFooterActions([
                        Tables\Actions\Action::make('approve')
                            ->label('Approve Audit')
                            ->color('success')
                            ->icon('heroicon-o-check-circle')
                            ->action(function (AuditAssignment $record, array $data): void {
                                $record->update([
                                    'status' => AuditAssignmentStatus::Approved,
                                    'approved_at' => now(),
                                    'admin_remarks' => $data['admin_remarks'] ?? null,
                                ]);
                                Notification::make()
                                    ->title('Audit approved successfully')
                                    ->success()
                                    ->send();
                            }),
                        Tables\Actions\Action::make('reject')
                            ->label('Reject Audit')
                            ->color('danger')
                            ->icon('heroicon-o-x-circle')
                            ->action(function (AuditAssignment $record, array $data): void {
                                $record->update([
                                    'status' => AuditAssignmentStatus::Rejected,
                                    'rejected_at' => now(),
                                    'admin_remarks' => $data['admin_remarks'] ?? null,
                                ]);
                                Notification::make()
                                    ->title('Audit rejected')
                                    ->danger()
                                    ->send();
                            }),
                    ]),

                // Details view for other statuses
                Tables\Actions\ViewAction::make()
                    ->label('Details')
                    ->visible(fn (AuditAssignment $record): bool => $record->status !== AuditAssignmentStatus::Submitted),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No audit assignments yet')
            ->emptyStateDescription('Assign faculty members to conduct peer teaching audits.')
            ->emptyStateIcon('heroicon-o-document-magnifying-glass');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditAssignments::route('/'),
            'create' => Pages\CreateAuditAssignment::route('/create'),
            'edit' => Pages\EditAuditAssignment::route('/{record}/edit'),
        ];
    }
}
