<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_evidence_files', function (Blueprint $table) {
            $table->string('client_attachment_id', 64)->nullable();
            $table->string('original_sha256', 64)->nullable();
            $table->unique(['audit_assignment_id', 'client_attachment_id'], 'audit_evidence_retry_identity');
        });
    }

    public function down(): void
    {
        Schema::table('audit_evidence_files', function (Blueprint $table) {
            $table->dropUnique('audit_evidence_retry_identity');
            $table->dropColumn(['client_attachment_id', 'original_sha256']);
        });
    }
};
