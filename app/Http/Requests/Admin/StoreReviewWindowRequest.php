<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreReviewWindowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user || ! $user->isAdmin()) {
            return false;
        }

        // Delegated admin cannot create a window for another department
        if (! $user->isCentralQa() && $this->filled('department_id')) {
            if ((int) $this->input('department_id') !== (int) $user->department_id) {
                return false;
            }
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'term' => ['nullable', 'string', 'max:50'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'form_version_id' => [
                'nullable',
                'integer',
                \Illuminate\Validation\Rule::exists('form_versions', 'id')
                    ->where('form_type', \App\Enums\FormType::StudentReview->value)
                    ->where('is_published', true),
            ],
            'section_ids' => ['nullable', 'array'],
            'section_ids.*' => ['integer', 'exists:sections,id'],
            'description' => ['nullable', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ];
    }

    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $sectionIds = $this->input('section_ids', []);
                if (empty($sectionIds)) {
                    return;
                }

                $term = $this->input('term');
                $user = $this->user();
                $deptId = $this->input('department_id') ?? (! $user?->isCentralQa() ? $user?->department_id : null);

                $sections = \App\Models\Section::with('course')->whereIn('id', $sectionIds)->get();
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
