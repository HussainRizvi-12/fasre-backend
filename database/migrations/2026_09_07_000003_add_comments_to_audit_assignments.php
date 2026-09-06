<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-question auditor comments for peer audits, keyed by question id.
 * Kept separate from answers_json so scoring/aggregation logic never has
 * to touch free text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_assignments', function (Blueprint $table) {
            $table->json('comments_json')->nullable()->after('answers_json');
        });
    }

    public function down(): void
    {
        Schema::table('audit_assignments', function (Blueprint $table) {
            $table->dropColumn('comments_json');
        });
    }
};
