<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_versions', function (Blueprint $table) {
            $table->id();
            $table->string('form_type'); // student_review, faculty_audit
            $table->string('version_code'); // e.g. v1.0, v1.0-legacy
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('questions_json'); // frozen snapshot of questions
            $table->json('scoring_rules_json')->nullable(); // frozen scoring rules, outcome bands, anchors
            $table->boolean('is_published')->default(true);
            $table->boolean('is_legacy_reconstruction')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['form_type', 'version_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_versions');
    }
};
