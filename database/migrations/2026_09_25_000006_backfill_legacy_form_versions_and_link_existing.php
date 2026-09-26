<?php

use App\Enums\FormType;
use App\Models\AuditAssignment;
use App\Models\FormVersion;
use App\Models\Question;
use App\Models\ReviewWindow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('questions') || ! Schema::hasTable('form_versions')) {
            return;
        }

        // 1. Backfill student review form version if any questions exist
        $studentQuestions = DB::table('questions')
            ->where('form_type', 'student_review')
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($q) => [
                'id' => $q->id,
                'question_text' => $q->question_text,
                'question_type' => $q->question_type,
                'is_required' => (bool) $q->is_required,
                'sort_order' => $q->sort_order,
            ])
            ->all();

        $studentVersionId = null;
        if (! empty($studentQuestions)) {
            $studentVersionId = DB::table('form_versions')->insertGetId([
                'form_type' => 'student_review',
                'version_code' => 'v1.0-legacy',
                'title' => 'Student Course Review Instrument (Legacy v1.0)',
                'description' => 'Automatically reconstructed legacy form version from pre-existing active questions.',
                'questions_json' => json_encode($studentQuestions),
                'scoring_rules_json' => json_encode([
                    'type' => 'legacy',
                    'anonymity_threshold' => 5,
                    'scale_min' => 1,
                    'scale_max' => 5,
                ]),
                'is_published' => true,
                'is_legacy_reconstruction' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Backfill faculty audit form version if any questions exist
        $facultyQuestions = DB::table('questions')
            ->where('form_type', 'faculty_audit')
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($q) => [
                'id' => $q->id,
                'question_text' => $q->question_text,
                'question_type' => $q->question_type,
                'is_required' => (bool) $q->is_required,
                'sort_order' => $q->sort_order,
            ])
            ->all();

        $facultyVersionId = null;
        if (! empty($facultyQuestions)) {
            $facultyVersionId = DB::table('form_versions')->insertGetId([
                'form_type' => 'faculty_audit',
                'version_code' => 'v1.0-legacy',
                'title' => 'Peer Classroom Observation Rubric (Legacy v1.0)',
                'description' => 'Automatically reconstructed legacy rubric version from pre-existing active questions.',
                'questions_json' => json_encode($facultyQuestions),
                'scoring_rules_json' => json_encode([
                    'type' => 'legacy_indexed',
                    'rating_multiplier' => 20,
                    'yes_value' => 100,
                    'no_value' => 0,
                    'bands' => [
                        ['min' => 85, 'label' => 'Commendable'],
                        ['min' => 60, 'label' => 'Satisfactory'],
                        ['min' => 0, 'label' => 'Action Required'],
                    ],
                ]),
                'is_published' => true,
                'is_legacy_reconstruction' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Link unlinked review windows
        if ($studentVersionId && Schema::hasColumn('review_windows', 'form_version_id')) {
            DB::table('review_windows')
                ->whereNull('form_version_id')
                ->update(['form_version_id' => $studentVersionId]);
        }

        // 4. Link unlinked audit assignments & mark as legacy
        if ($facultyVersionId && Schema::hasColumn('audit_assignments', 'form_version_id')) {
            DB::table('audit_assignments')
                ->whereNull('form_version_id')
                ->update([
                    'form_version_id' => $facultyVersionId,
                    'is_legacy_assignment' => true,
                ]);
        }
    }

    public function down(): void
    {
        DB::table('form_versions')
            ->where('is_legacy_reconstruction', true)
            ->delete();
    }
};
