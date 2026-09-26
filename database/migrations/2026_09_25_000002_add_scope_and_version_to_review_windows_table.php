<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_windows', function (Blueprint $table) {
            $table->string('term')->nullable()->after('title');
            $table->foreignId('department_id')->nullable()->after('term')->constrained('departments')->nullOnDelete();
            $table->foreignId('form_version_id')->nullable()->after('department_id')->constrained('form_versions')->nullOnDelete();
            $table->dateTime('published_at')->nullable()->after('status');
            $table->softDeletes();
        });

        Schema::create('review_window_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_window_id')->constrained('review_windows')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['review_window_id', 'section_id']);
        });

        Schema::create('review_window_rosters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_window_id')->constrained('review_windows')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('eligible'); // eligible, withdrawn, added
            $table->string('reason')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['review_window_id', 'section_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_window_rosters');
        Schema::dropIfExists('review_window_sections');

        Schema::table('review_windows', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['published_at', 'term']);
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('form_version_id');
        });
    }
};
