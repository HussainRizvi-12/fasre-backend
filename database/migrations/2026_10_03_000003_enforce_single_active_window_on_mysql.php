<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('review_windows', function (Blueprint $table) {
                $table->unsignedTinyInteger('active_window_guard')->nullable()
                    ->virtualAs("CASE WHEN status = 'active' THEN 1 ELSE NULL END");
                $table->unique('active_window_guard', 'unique_active_review_window');
            });
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('review_windows', function (Blueprint $table) {
                $table->dropUnique('unique_active_review_window');
                $table->dropColumn('active_window_guard');
            });
        }
    }
};
