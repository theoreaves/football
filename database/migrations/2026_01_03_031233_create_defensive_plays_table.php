<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('defense_plays', function (Blueprint $table) {
            $table->id();

            // e.g. "IR", "OR"
            $table->string('code', 10)->unique();

            // e.g. "Inside Run"
            $table->string('name');

            $table->timestamps();
        });

        Schema::create('defense_play_rolls', function (Blueprint $table) {
            $table->id();

            $table->foreignId('defense_play_id')
                ->constrained('defense_plays')
                ->cascadeOnDelete();

            // Original key like "10" or "11-12"
            $table->string('roll_label', 20);

            // Parsed numeric range
            $table->unsignedSmallInteger('roll_min');
            $table->unsignedSmallInteger('roll_max');

            $table->string('player', 30);
            $table->string('rating', 30);

            // Keep these as strings because you have values like "5 + R", "B!", "FF 2", "13!"
            $table->string('skill_pass', 30);
            $table->string('skill_fail', 30);

            // Keep a deterministic ordering (optional but useful)
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // Speed lookups by play + roll
            $table->index(['defense_play_id', 'roll_min', 'roll_max']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('defense_play_rolls');
        Schema::dropIfExists('defense_plays');
    }
};
