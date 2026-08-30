<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\FacultyAssignmentResource\Pages;
use App\Models\FacultyAssignment;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FacultyAssignmentResource extends Resource
{
    protected static ?string $model = FacultyAssignment::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'People & Enrollments';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Card::make()
                    ->schema([
                        Forms\Components\Select::make('section_id')
                            ->relationship('section', 'name')
                            ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->name} — {$record->course?->code} ({$record->term})")
                            ->required()
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('faculty_id')
                            ->label('Faculty Member')
                            ->options(fn () => User::where('role', UserRole::Faculty)->where('is_active', true)->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->preload(),
                        Forms\Components\Toggle::make('is_primary')
                            ->label('Is Primary Faculty')
                            ->helperText('Setting as primary will automatically unmark any previous primary faculty for this section.')
                            ->default(true),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['section.course', 'faculty']))
            ->columns([
                Tables\Columns\TextColumn::make('section.name')
                    ->label('Section')
                    ->description(fn (FacultyAssignment $record): string => "{$record->section?->course?->title} ({$record->section?->course?->code})")
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('faculty.name')
                    ->label('Faculty Name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\IconColumn::make('is_primary')
                    ->label('Primary')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('success')
                    ->falseColor('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('section_id')
                    ->relationship('section', 'name')
                    ->label('Section'),
                Tables\Filters\SelectFilter::make('faculty_id')
                    ->relationship('faculty', 'name')
                    ->label('Faculty'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No faculty assignments yet')
            ->emptyStateDescription('Assign faculty members to course sections.')
            ->emptyStateIcon('heroicon-o-user-plus')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()->label('+ Assign Faculty'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFacultyAssignments::route('/'),
            'create' => Pages\CreateFacultyAssignment::route('/create'),
            'edit' => Pages\EditFacultyAssignment::route('/{record}/edit'),
        ];
    }
}
