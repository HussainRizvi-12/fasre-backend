<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audit_assignments', function (Blueprint $table) {
            $table->string('outcome_band')->nullable()->after('total_score');
        });

        Schema::create('audit_improvement_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_assignment_id')
                ->constrained('audit_assignments')
                ->onDelete('cascade');
            $table->text('finding');
            $table->text('agreed_action');
            $table->foreignId('owner_id')
                ->constrained('users')
                ->onDelete('restrict');
            $table->date('due_date');
            $table->string('status')->default('open'); // open, in_progress, completed, closed
            $table->text('follow_up_note')->nullable();
            $table->foreignId('closed_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['audit_assignment_id', 'status']);
            $table->index(['owner_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_improvement_actions');

        Schema::table('audit_assignments', function (Blueprint $table) {
            $table->dropColumn('outcome_band');
        });
    }
};
