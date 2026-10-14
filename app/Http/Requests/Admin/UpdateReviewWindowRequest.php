<?php

namespace App\Http\Requests\Admin;

use App\Enums\FormType;
use App\Models\ReviewWindow;
use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateReviewWindowRequest extends FormRequest
{
    private ?ReviewWindow $lockedWindow = null;

    public function validatedForWindow(ReviewWindow $window): array
    {
        $this->lockedWindow = $window;
        $validator = \Illuminate\Support\Facades\Validator::make($this->all(), $this->rules());
        foreach ($this->after() as $check) {
            $validator->after($check);
        }

        return $validator->validate();
    }

    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user || ! $user->isAdmin()) {
            return false;
        }

        $window = $this->route('review_window') ?? $this->route('reviewWindow');
        if (! $window instanceof ReviewWindow) {
            $window = ReviewWindow::find($window);
        }

        if ($window && ! $user->canAccessDepartment($window->department_id)) {
            return false;
        }

        // Delegated admin cannot reassign window to another department
        if (! $user->isCentralQa() && $this->exists('department_id')) {
            if ($this->input('department_id') === null || (int) $this->input('department_id') !== (int) $user->department_id) {
                return false;
            }
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'term' => ['nullable', 'string', 'max:50'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'form_version_id' => [
                'nullable',
                'integer',
                Rule::exists('form_versions', 'id')
                    ->where('form_type', FormType::StudentReview->value)
                    ->where('is_published', true),
            ],
            'section_ids' => ['nullable', 'array'],
            'section_ids.*' => ['integer', 'exists:sections,id'],
            'description' => ['nullable', 'string'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $window = $this->lockedWindow ?? $this->route('review_window') ?? $this->route('reviewWindow');
                if (! $window instanceof ReviewWindow) {
                    $window = ReviewWindow::find($window);
                }

                // PATCH may supply just one date; compare the complete resulting interval.
                $startsAt = $this->input('starts_at', $window?->starts_at);
                $endsAt = $this->input('ends_at', $window?->ends_at);
                if ($startsAt && $endsAt && ! Carbon::parse($endsAt)->greaterThan(Carbon::parse($startsAt))) {
                    $validator->errors()->add('ends_at', 'The end date must be after the start date.');
                }

                // Retained explicit sections must still match a changed term/department.
                $sectionIds = $this->input('section_ids') ?? $window?->sections()->pluck('sections.id')->all() ?? [];
                if (empty($sectionIds)) {
                    return;
                }

                $term = $this->input('term', $window?->term);
                $user = $this->user();
                $deptId = $this->input('department_id', $window?->department_id);

                $sections = Section::with('course')->whereIn('id', $sectionIds)->get();
                foreach ($sections as $section) {
                    if ($term && $section->term !== $term) {
                        $validator->errors()->add('section_ids', "Section '{$section->name}' (term: {$section->term}) does not match the window term '{$term}'.");
                    }
                    if ($deptId && (int) $section->course?->department_id !== (int) $deptId) {
                        $validator->errors()->add('section_ids', "Section '{$section->name}' does not belong to the review window's target department.");
                    }
                    if ($user && ! $user->isCentralQa() && ! $user->canAccessDepartment($section->course?->department_id)) {
                        $validator->errors()->add('section_ids', "Section '{$section->name}' is outside your authorized department scope.");
                    }
                }
            },
        ];
    }
}
