<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the hot-path indexes missing from the original schema.
 *
 * - review_responses: every aggregation endpoint (admin results, student
 *   published results, CSV export) filters
 *   WHERE review_window_id = ? AND section_id = ?. PostgreSQL does not
 *   auto-index FK columns, so without this composite index each request
 *   performed a sequential scan.
 * - audit_assignments: assigned-audits / my-submissions / my-reports /
 *   admin listings all filter on auditor_id, auditee_id, or status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_responses', function (Blueprint $table) {
            $table->index(
                ['review_window_id', 'section_id'],
                'review_responses_window_section_idx'
            );
            $table->index(['section_id', 'submitted_at'], 'review_responses_section_submitted_idx');
        });

        Schema::table('audit_assignments', function (Blueprint $table) {
            $table->index('auditor_id', 'audit_assignments_auditor_idx');
            $table->index('auditee_id', 'audit_assignments_auditee_idx');
            $table->index('status', 'audit_assignments_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('review_responses', function (Blueprint $table) {
            $table->dropIndex('review_responses_window_section_idx');
            $table->dropIndex('review_responses_section_submitted_idx');
        });

        Schema::table('audit_assignments', function (Blueprint $table) {
            $table->dropIndex('audit_assignments_auditor_idx');
            $table->dropIndex('audit_assignments_auditee_idx');
            $table->dropIndex('audit_assignments_status_idx');
        });
    }
};
