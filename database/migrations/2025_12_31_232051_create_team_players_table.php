<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('team_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('team_year');
            $table->string('position');
            $table->string('depth_chart_position');
            $table->string('kick_return_depth_chart_position');
            $table->string('punt_return_depth_chart_position');
            $table->integer('catch_from');
            $table->integer('catch_to');
            $table->integer('catch_plus_from');
            $table->integer('catch_plus_to');
            $table->integer('rush_from');
            $table->integer('rush_to');
            $table->integer('sack_from');
            $table->integer('sack_to');
            $table->integer('interception_from');
            $table->integer('interception_to');
            $table->integer('tackle_from');
            $table->integer('tackle_to');
            $table->integer('kick_from');
            $table->integer('kick_to');
            $table->integer('punt_from');
            $table->integer('punt_to');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_players');
    }
};
