<?php

namespace App\Http\Requests\Faculty;

use App\Enums\AuditAssignmentStatus;
use App\Models\AuditAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveAuditDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user() || ! $this->user()->isFaculty()) {
            return false;
        }

        $auditId = $this->route('id') ?? $this->route('audit');
        if (! $auditId) {
            return true;
        }

        $audit = AuditAssignment::find($auditId);
        if ($audit && $audit->auditor_id !== $this->user()->id) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'answers' => ['nullable', 'array', 'max:100'],
            'answers.*.question_id' => ['required_with:answers', 'integer', 'exists:questions,id'],
            // Scalar-only + bounded length (see SubmitStudentReviewRequest).
            'answers.*.value' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_array($value) || is_object($value)) {
                    $fail('Answer values must be plain text, numbers, or booleans.');
                    return;
                }
                if (is_string($value) && strlen($value) > 5000) {
                    $fail('Each text answer may not exceed 5000 characters.');
                }
            }],
            // Free-text recommendations from the review summary box.
            'recommendations' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $auditId = $this->route('id') ?? $this->route('audit');
                $audit = AuditAssignment::find($auditId);

                if (! $audit) {
                    $validator->errors()->add('audit', 'Audit assignment not found.');
                    return;
                }

                // Submitted and Approved audits are finalized. A Rejected audit
                // MUST accept revised drafts — that is the resubmission flow.
                if (in_array($audit->status, [AuditAssignmentStatus::Submitted, AuditAssignmentStatus::Approved], true)) {
                    $validator->errors()->add('audit', 'Cannot save draft. This audit has already been submitted and is finalized.');
                }
            },
        ];
    }
}
