<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // If you used enum for phase, easiest early in dev is to make it string.
            // If you want to keep enum, you may need a fresh migration or change via raw SQL.
            // We'll do safe approach: add kick_team first.
            $table->string('kick_team')->nullable(); // HOME/AWAY
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('kick_team');
        });
    }
};
