<?php

namespace App\Filament\Pages;

use App\Enums\FormType;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\ReviewResponse;
use App\Models\ReviewWindow;
use App\Models\Section;
use Filament\Forms\Components\Card;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class ReviewResults extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'Analytics & Reports';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.review-results';

    protected static ?string $title = 'Student Review Results';

    public ?int $review_window_id = null;

    public ?int $section_id = null;

    public function mount(): void
    {
        $this->review_window_id = request()->query('review_window_id')
            ?? ReviewWindow::whereIn('status', ['active', 'published', 'closed'])->latest()->value('id');

        $this->form->fill([
            'review_window_id' => $this->review_window_id,
            'section_id' => $this->section_id,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Card::make()
                    ->schema([
                        Select::make('review_window_id')
                            ->label('Select Review Window')
                            ->options(ReviewWindow::pluck('title', 'id'))
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn ($state) => $this->review_window_id = (int) $state),

                        Select::make('section_id')
                            ->label('Filter by Section (Optional)')
                            ->options(function () {
                                return Section::with('course')->get()->mapWithKeys(function ($section) {
                                    return [$section->id => "{$section->name} — {$section->course?->code} ({$section->term})"];
                                });
                            })
                            ->placeholder('All Sections')
                            ->live()
                            ->afterStateUpdated(fn ($state) => $this->section_id = $state ? (int) $state : null),
                    ])->columns(2),
            ]);
    }

    public function getSectionsProperty(): Collection
    {
        if (! $this->review_window_id) {
            return collect();
        }

        $query = Section::with(['course.department', 'facultyAssignments.faculty']);

        if ($this->section_id) {
            $query->where('id', $this->section_id);
        }

        return $query->get();
    }

    /**
     * Compute aggregated evaluation metrics for a specific section and review window.
     */
    public function getAggregatedResults(int $sectionId): array
    {
        $responses = ReviewResponse::where('review_window_id', $this->review_window_id)
            ->where('section_id', $sectionId)
            ->get();

        $responseCount = $responses->count();
        $isSuppressed = $responseCount < 5;

        if ($isSuppressed) {
            return [
                'response_count' => $responseCount,
                'is_suppressed' => true,
                'questions_data' => [],
            ];
        }

        $questions = Question::where('form_type', FormType::StudentReview)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $questionsData = [];

        foreach ($questions as $q) {
            $qId = (string) $q->id;
            $answers = $responses->pluck("answers_json.{$qId}")->filter(fn ($val) => ! is_null($val));

            if ($q->question_type === QuestionType::Rating) {
                $avg = $answers->count() > 0 ? round($answers->avg(), 1) : 0;
                $questionsData[] = [
                    'text' => $q->question_text,
                    'type' => 'rating',
                    'average' => $avg,
                    'max' => 5,
                    'percentage' => round(($avg / 5) * 100),
                ];
            } elseif ($q->question_type === QuestionType::YesNo) {
                $yesCount = $answers->filter(fn ($val) => in_array($val, [true, 1, 'yes', '1'], true))->count();
                $yesPct = $answers->count() > 0 ? round(($yesCount / $answers->count()) * 100) : 0;
                $questionsData[] = [
                    'text' => $q->question_text,
                    'type' => 'yes_no',
                    'percentage_yes' => $yesPct,
                ];
            } else {
                // text / textarea: count only (anonymity guarantee)
                $questionsData[] = [
                    'text' => $q->question_text,
                    'type' => 'text',
                    'submission_count' => $answers->count(),
                ];
            }
        }

        return [
            'response_count' => $responseCount,
            'is_suppressed' => false,
            'questions_data' => $questionsData,
        ];
    }
}
