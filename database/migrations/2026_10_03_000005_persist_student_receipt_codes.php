<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_participations', function (Blueprint $table) {
            // Receipt identity belongs to participation, never anonymous answers.
            // Legacy submissions have no recoverable original receipt code.
            $table->string('confirmation_code', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('review_participations', function (Blueprint $table) {
            $table->dropUnique(['confirmation_code']);
            $table->dropColumn('confirmation_code');
        });
    }
};
