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
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->string('firstname');
            $table->string('lastname');
            $table->integer('age');
            $table->string('position');
            $table->integer('pass_evade')->default(0);
            $table->integer('pass_accuracy')->default(0);
            $table->integer('pass_deep')->default(0);
            $table->integer('pass_control')->default(0);
            $table->integer('rush')->default(0);
            $table->integer('rush_power')->default(0);
            $table->integer('receive')->default(0);
            $table->integer('receive_deep')->default(0);
            $table->integer('fumble')->default(0);
            $table->integer('speed')->default(0);
            $table->integer('tackle')->default(0);
            $table->integer('sack')->default(0);
            $table->integer('cover')->default(0);
            $table->integer('interception')->default(0);
            $table->integer('strip')->default(0);
            $table->integer('kick30')->default(0);
            $table->integer('kick39')->default(0);
            $table->integer('kick49')->default(0);
            $table->integer('kick50')->default(0);
            $table->integer('punt_distance')->default(0);
            $table->integer('punt_pooch_yard')->default(0);
            $table->integer('punt_pooch')->default(0);
            $table->integer('punt_block')->default(0);
            $table->integer('return_yards')->default(0);
            $table->integer('return_speed')->default(0);
            $table->integer('return_fumble')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
