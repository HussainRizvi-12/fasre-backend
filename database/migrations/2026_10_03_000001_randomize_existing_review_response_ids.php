<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // No table references response IDs. Preserve answers and pseudonyms,
        // discard the sequential IDs, and never persist an old/new ID map.
        DB::transaction(function () {
            $ids = DB::table('review_responses')->pluck('id')->all();
            shuffle($ids);
            foreach ($ids as $oldId) {
                do {
                    $newId = random_int(1, 9007199254740991);
                } while (DB::table('review_responses')->where('id', $newId)->exists());
                DB::table('review_responses')->where('id', $oldId)->update(['id' => $newId]);
            }
        });
    }

    public function down(): void
    {
        // Restoring submission order would reintroduce the privacy leak.
    }
};
