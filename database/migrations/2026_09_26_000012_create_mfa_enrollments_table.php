<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Upgrade installations which already ran the original MFA migration.
        if (! Schema::hasColumn('users', 'mfa_required')) {
            Schema::table('users', fn (Blueprint $table) => $table->boolean('mfa_required')->default(false));
        }
        Schema::create('mfa_enrollments', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('token_id');
            $table->text('payload');
            $table->timestamp('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_enrollments');
    }
};
