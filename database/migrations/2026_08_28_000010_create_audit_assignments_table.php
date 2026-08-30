<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auditor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('auditee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('assigned'); // assigned, in_progress, submitted, approved, rejected
            $table->date('due_date')->nullable();
            $table->json('answers_json')->nullable();
            $table->decimal('total_score', 8, 2)->nullable();
            $table->text('admin_remarks')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->timestamps();

            // NOTE: auditor_id != auditee_id is enforced at the application/Form Request level,
            // not as a DB constraint. See the AuditAssignment model for details.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_assignments');
    }
};
