<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_central_qa')->default(false));
        // Preserve existing intentional central accounts once. Subsequent
        // department changes never grant this privilege automatically.
        DB::table('users')->where('role', 'admin')->whereNull('department_id')->update(['is_central_qa' => true]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_central_qa'));
    }
};
