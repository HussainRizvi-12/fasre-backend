<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_windows', function (Blueprint $table) {
            $table->boolean('roster_is_frozen')->default(false);
        });

        // Preserve legacy enrollment fallback only for windows without any
        // evidence of a snapshot. An activation with zero entries is still frozen.
        DB::table('review_windows')->where(function ($query) {
            $query->whereIn('id', DB::table('review_window_rosters')->select('review_window_id'))
                ->orWhereIn('id', DB::table('audit_provenance_logs')
                    ->where('auditable_type', 'App\\Models\\ReviewWindow')
                    ->where('action', 'activated')->select('auditable_id'));
        })->update(['roster_is_frozen' => true]);
    }

    public function down(): void
    {
        Schema::table('review_windows', function (Blueprint $table) {
            $table->dropColumn('roster_is_frozen');
        });
    }
};
