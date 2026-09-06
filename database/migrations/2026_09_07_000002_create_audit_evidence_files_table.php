<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence attachments for faculty peer audits (photos, signature images,
 * PDFs). Files are stored on the local disk under
 * storage/app/private/audit-evidence/{audit_assignment_id}/ and served
 * through an authorized download endpoint — never as public URLs.
 *
 * The table hangs off audit_assignments (the audit itself is attributed,
 * peer-reviewed work — NOT anonymous student data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_evidence_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_assignment_id')->constrained('audit_assignments')->cascadeOnDelete();
            $table->unsignedBigInteger('question_id')->nullable()->index();
            $table->string('original_name');
            $table->string('stored_path')->unique();
            $table->string('mime_type', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_evidence_files');
    }
};
