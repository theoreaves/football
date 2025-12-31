<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedTinyInteger('quarter')->default(1);          // 1..4
            $table->unsignedSmallInteger('clock')->default(15 * 60);     // seconds left in quarter
            $table->string('first_kick_team')->nullable();               // 'HOME' or 'AWAY'

            // Per-quarter scoring: [Q1, Q2, Q3, Q4]
            $table->json('home_q')->nullable();
            $table->json('away_q')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['quarter','clock','first_kick_team','home_q','away_q']);
        });
    }
};
