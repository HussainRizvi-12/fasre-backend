<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\StudentEnrollmentResource\Pages;
use App\Models\StudentEnrollment;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StudentEnrollmentResource extends Resource
{
    protected static ?string $model = StudentEnrollment::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'People';

    protected static ?int $navigationSort = 3;

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

                        Forms\Components\Select::make('student_ids')
                            ->label('Students to Enroll')
                            ->options(fn () => User::where('role', UserRole::Student)->where('is_active', true)->pluck('name', 'id'))
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->preload()
                            ->visibleOn('create')
                            ->helperText('You can select multiple students to enroll in this section at once.'),

                        Forms\Components\Select::make('student_id')
                            ->label('Student')
                            ->options(fn () => User::where('role', UserRole::Student)->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->visibleOn('edit'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['section.course', 'student']))
            ->columns([
                Tables\Columns\TextColumn::make('section.name')
                    ->label('Section')
                    ->description(fn (StudentEnrollment $record): string => "{$record->section?->course?->title} ({$record->section?->course?->code})")
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('student.name')
                    ->label('Student Name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('student.email')
                    ->label('Student Email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Enrolled Date')
                    ->dateTime('M d, Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('section_id')
                    ->relationship('section', 'name')
                    ->label('Section'),
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
            ->emptyStateHeading('No student enrollments yet')
            ->emptyStateDescription('Enroll students into course sections.')
            ->emptyStateIcon('heroicon-o-user-group')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()->label('+ Enroll Student'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStudentEnrollments::route('/'),
            'create' => Pages\CreateStudentEnrollment::route('/create'),
            'edit' => Pages\EditStudentEnrollment::route('/{record}/edit'),
        ];
    }
}
