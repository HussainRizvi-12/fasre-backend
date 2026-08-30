<?php

namespace App\Filament\Resources;

use App\Enums\FormType;
use App\Enums\QuestionType;
use App\Filament\Resources\AuditQuestionResource\Pages;
use App\Models\Question;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditQuestionResource extends Resource
{
    protected static ?string $model = Question::class;

    protected static ?string $modelLabel = 'Audit Question';

    protected static ?string $pluralModelLabel = 'Audit Questions';

    protected static ?string $navigationLabel = 'Audit Questions';

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Evaluation Config';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('form_type', FormType::FacultyAudit);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Card::make()
                    ->schema([
                        Forms\Components\Hidden::make('form_type')
                            ->default(FormType::FacultyAudit->value),
                        Forms\Components\Textarea::make('question_text')
                            ->label('Question Text')
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\Select::make('question_type')
                            ->label('Question Type')
                            ->options([
                                QuestionType::Rating->value => 'Rating (1-5)',
                                QuestionType::YesNo->value => 'Yes / No',
                                QuestionType::Text->value => 'Short Text',
                                QuestionType::Textarea->value => 'Textarea / Long Feedback',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('sort_order')
                            ->label('Sort Order')
                            ->numeric()
                            ->default(0),
                        Forms\Components\Toggle::make('is_required')
                            ->label('Required Question')
                            ->default(true),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Order #')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('question_text')
                    ->label('Question Text')
                    ->limit(65)
                    ->searchable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('question_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ($state instanceof QuestionType ? $state->value : (string) $state) {
                        'rating' => 'Rating (1-5)',
                        'yes_no' => 'Yes / No',
                        'text' => 'Short Text',
                        'textarea' => 'Long Feedback',
                        default => ucfirst($state instanceof QuestionType ? $state->value : (string) $state),
                    })
                    ->color(fn ($state): string => match ($state instanceof QuestionType ? $state->value : (string) $state) {
                        'rating' => 'primary',
                        'yes_no' => 'success',
                        'text' => 'info',
                        'textarea' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_required')
                    ->label('Required')
                    ->boolean(),
                Tables\Columns\TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('question_type')
                    ->options([
                        QuestionType::Rating->value => 'Rating',
                        QuestionType::YesNo->value => 'Yes / No',
                        QuestionType::Text->value => 'Text',
                        QuestionType::Textarea->value => 'Textarea',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status'),
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
            ->emptyStateHeading('No faculty audit questions yet')
            ->emptyStateDescription('Create evaluation criteria for peer faculty audits.')
            ->emptyStateIcon('heroicon-o-clipboard-document-check');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditQuestions::route('/'),
            'create' => Pages\CreateAuditQuestion::route('/create'),
            'edit' => Pages\EditAuditQuestion::route('/{record}/edit'),
        ];
    }
}
