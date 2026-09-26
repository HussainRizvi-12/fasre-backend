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
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable()->after('is_active');
            $table->json('mfa_recovery_codes')->nullable()->after('mfa_secret');
            $table->timestamp('mfa_enabled_at')->nullable()->after('mfa_recovery_codes');
            $table->boolean('mfa_required')->default(false)->after('mfa_enabled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_recovery_codes', 'mfa_enabled_at', 'mfa_required']);
        });
    }
};
