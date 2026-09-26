<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_assignments', function (Blueprint $table) {
            $table->foreignId('form_version_id')->nullable()->after('section_id')->constrained('form_versions')->nullOnDelete();
            $table->date('observation_date')->nullable()->after('due_date');
            $table->text('observation_context')->nullable()->after('observation_date');
            $table->boolean('conflict_declared')->default(false)->after('observation_context');
            $table->unsignedInteger('revision_number')->default(1)->after('conflict_declared');
            $table->json('previous_version_answers_json')->nullable()->after('comments_json');
            $table->json('previous_version_comments_json')->nullable()->after('previous_version_answers_json');
            $table->text('rejection_reason')->nullable()->after('admin_remarks');
            $table->text('faculty_response')->nullable()->after('rejection_reason');
            $table->dateTime('faculty_responded_at')->nullable()->after('faculty_response');
            $table->boolean('is_legacy_assignment')->default(false)->after('faculty_responded_at');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('audit_assignments', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropConstrainedForeignId('form_version_id');
            $table->dropColumn([
                'observation_date',
                'observation_context',
                'conflict_declared',
                'revision_number',
                'previous_version_answers_json',
                'previous_version_comments_json',
                'rejection_reason',
                'faculty_response',
                'faculty_responded_at',
                'is_legacy_assignment',
            ]);
        });
    }
};
