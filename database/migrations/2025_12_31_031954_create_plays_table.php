<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('seq');

            $table->string('type'); // RUN, PASS, PENALTY, PUNT, etc.
            $table->integer('yards')->default(0);
            $table->string('note')->nullable();

            // Before
            $table->string('possession_before');
            $table->enum('side_before', ['OWN', 'OPP']);
            $table->unsignedTinyInteger('yardline_before');
            $table->unsignedTinyInteger('down_before');
            $table->unsignedTinyInteger('togo_before');

            // After
            $table->string('possession_after');
            $table->enum('side_after', ['OWN', 'OPP']);
            $table->unsignedTinyInteger('yardline_after');
            $table->unsignedTinyInteger('down_after');
            $table->unsignedTinyInteger('togo_after');

            // Flags
            $table->boolean('first_down')->default(false);
            $table->boolean('turnover')->default(false);
            $table->boolean('touchdown')->default(false);

            $table->timestamps();

            $table->index(['game_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plays');
    }
};
