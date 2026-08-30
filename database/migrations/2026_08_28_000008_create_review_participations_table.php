<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_window_id')->constrained('review_windows')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('submitted_at');
            $table->timestamps();

            // One submission per student per section per review window
            $table->unique(['review_window_id', 'section_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_participations');
    }
};
