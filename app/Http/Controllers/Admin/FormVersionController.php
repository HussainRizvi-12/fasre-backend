<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FormType;
use App\Http\Controllers\Controller;
use App\Models\AuditProvenanceLog;
use App\Models\FormVersion;
use App\Models\Question;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FormVersionController extends Controller
{
    /**
     * GET /api/admin/form-versions
     * List all published/frozen form versions.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FormVersion::with('creator')->orderByDesc('created_at');

        if ($request->has('form_type')) {
            $query->where('form_type', $request->query('form_type'));
        }

        $versions = $query->get()->map(fn (FormVersion $fv) => [
            'id' => $fv->id,
            'form_type' => $fv->form_type,
            'version_code' => $fv->version_code,
            'title' => $fv->title,
            'description' => $fv->description,
            'is_published' => (bool) $fv->is_published,
            'is_legacy_reconstruction' => (bool) $fv->is_legacy_reconstruction,
            'questions_count' => count($fv->questions_json ?? []),
            'created_by' => [
                'id' => $fv->creator?->id,
                'name' => $fv->creator?->name,
            ],
            'created_at' => $fv->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $versions,
            'message' => 'Form versions retrieved successfully.',
        ]);
    }

    /**
     * GET /api/admin/form-versions/{formVersion}
     * Retrieve full details of a frozen form version including its questions and rubric rules.
     */
    public function show(FormVersion $formVersion): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $formVersion->id,
                'form_type' => $formVersion->form_type,
                'version_code' => $formVersion->version_code,
                'title' => $formVersion->title,
                'description' => $formVersion->description,
                'questions' => $formVersion->getQuestions(),
                'scoring_rules' => $formVersion->scoring_rules_json,
                'is_published' => (bool) $formVersion->is_published,
                'is_legacy_reconstruction' => (bool) $formVersion->is_legacy_reconstruction,
                'created_by' => [
                    'id' => $formVersion->creator?->id,
                    'name' => $formVersion->creator?->name,
                ],
                'created_at' => $formVersion->created_at?->toIso8601String(),
            ],
            'message' => 'Form version details retrieved successfully.',
        ]);
    }

    /**
     * POST /api/admin/form-versions
     * Publish a new immutable form version by snapshotting active questions.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'form_type' => ['required', 'string', Rule::in(['student_review', 'faculty_audit'])],
            'version_code' => ['required', 'string', 'max:50', Rule::unique('form_versions')->where(fn ($q) => $q->where('form_type', $request->input('form_type')))],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'scoring_rules_json' => ['nullable', 'array'],
        ]);

        $activeQuestions = Question::where('form_type', $validated['form_type'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($activeQuestions->isEmpty()) {
            throw ValidationException::withMessages([
                'form_type' => 'Cannot publish a form version because there are no active questions in the question bank for this form type.',
            ]);
        }

        $questionsSnapshot = $activeQuestions->map(fn ($q) => [
            'id' => $q->id,
            'form_type' => $q->form_type->value ?? (string) $q->form_type,
            'question_text' => $q->question_text,
            'question_type' => $q->question_type->value ?? (string) $q->question_type,
            'is_required' => (bool) $q->is_required,
            'sort_order' => (int) $q->sort_order,
        ])->all();

        $defaultScoringRules = [
            'bands' => [
                ['key' => 'commendable', 'min' => 90.0, 'max' => 100.0, 'label' => 'Commendable', 'outcome' => 'exceedsStandard', 'color' => 'success', 'requires_action_plan' => false],
                ['key' => 'satisfactory', 'min' => 75.0, 'max' => 89.99, 'label' => 'Satisfactory', 'outcome' => 'meetsStandard', 'color' => 'info', 'requires_action_plan' => false],
                ['key' => 'developmental', 'min' => 60.0, 'max' => 74.99, 'label' => 'Developmental', 'outcome' => 'needsImprovement', 'color' => 'warning', 'requires_action_plan' => true],
                ['key' => 'critical_concern', 'min' => 0.0, 'max' => 59.99, 'label' => 'Critical Concern', 'outcome' => 'needsImprovement', 'color' => 'danger', 'requires_action_plan' => true],
            ],
        ];

        $scoringRules = $validated['scoring_rules_json'] ?? $defaultScoringRules;
        if (! empty($scoringRules['bands'])) {
            foreach ($scoringRules['bands'] as $i => &$b) {
                if (! isset($b['min'], $b['max'], $b['label'])) {
                    throw ValidationException::withMessages([
                        "scoring_rules_json.bands.{$i}" => 'Each scoring band must specify min, max, and label.',
                    ]);
                }
                if (! isset($b['outcome'])) {
                    $lbl = strtolower($b['label']);
                    $b['outcome'] = (str_contains($lbl, 'critical') || str_contains($lbl, 'needs improvement') || ($b['requires_action_plan'] ?? false) || (float) $b['min'] < 60)
                        ? 'needsImprovement'
                        : ((str_contains($lbl, 'exemplary') || str_contains($lbl, 'commendable') || (float) $b['min'] >= 85) ? 'exceedsStandard' : 'meetsStandard');
                }
                if (! isset($b['color'])) {
                    $b['color'] = $b['outcome'] === 'needsImprovement' ? 'danger' : ($b['outcome'] === 'exceedsStandard' ? 'success' : 'info');
                }
            }
            unset($b);
        }

        $formVersion = FormVersion::create([
            'form_type' => $validated['form_type'],
            'version_code' => $validated['version_code'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'questions_json' => $questionsSnapshot,
            'scoring_rules_json' => $scoringRules,
            'is_published' => true,
            'is_legacy_reconstruction' => false,
            'created_by' => $request->user()->id,
        ]);

        ActivityLogger::log($formVersion, 'form_version.published', [
            'version_code' => $formVersion->version_code,
            'form_type' => $formVersion->form_type,
            'questions_count' => count($questionsSnapshot),
        ]);

        AuditProvenanceLog::record(
            $formVersion,
            'published',
            $request->user(),
            "Form version '{$formVersion->version_code}' published with " . count($questionsSnapshot) . " snapshotted questions.",
            null,
            [
                'id' => $formVersion->id,
                'form_type' => $formVersion->form_type,
                'version_code' => $formVersion->version_code,
                'questions_count' => count($questionsSnapshot),
            ]
        );

        return response()->json([
            'data' => [
                'id' => $formVersion->id,
                'form_type' => $formVersion->form_type,
                'version_code' => $formVersion->version_code,
                'title' => $formVersion->title,
                'description' => $formVersion->description,
                'questions_count' => count($questionsSnapshot),
                'is_published' => true,
                'created_at' => $formVersion->created_at?->toIso8601String(),
            ],
            'message' => 'Form version published successfully.',
        ], 201);
    }
}
