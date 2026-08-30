<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_window_id')->constrained('review_windows')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->string('pseudonym_token')->index();
            $table->json('answers_json');
            $table->dateTime('submitted_at');

            // IMPORTANT: This table deliberately has NO student_id or user_id column.
            // Anonymity is enforced by table isolation. Do NOT add identity columns here.
            //
            // ANONYMITY TIMESTAMP MITIGATION:
            // 1. Eloquent timestamps ($table->timestamps()) are explicitly excluded
            //    to eliminate created_at / updated_at sub-second precision timestamp leakage.
            // 2. submitted_at is deliberately stored at coarse date-level granularity (startOfDay).
            //    This prevents de-anonymization attacks where an attacker joins review_responses
            //    and review_participations on timestamp columns to correlate responses with
            //    participating student identities in small sections.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_responses');
    }
};
