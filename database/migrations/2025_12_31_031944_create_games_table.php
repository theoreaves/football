<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('home_name')->default('HOME');
            $table->string('away_name')->default('AWAY');

            // Possession: 'HOME' or 'AWAY'
            $table->string('possession')->default('HOME');

            // Ball position relative to the offense
            $table->enum('pos_side', ['OWN', 'OPP'])->default('OWN');
            $table->unsignedTinyInteger('pos_yardline')->default(25); // 1..50

            $table->unsignedTinyInteger('down')->default(1); // 1..4
            $table->unsignedTinyInteger('to_go')->default(10); // 1..99

            $table->unsignedSmallInteger('home_score')->default(0);
            $table->unsignedSmallInteger('away_score')->default(0);

            $table->unsignedInteger('play_seq')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
