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
        Schema::table('games', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->string('series_side')->nullable();      // 'OWN' or 'OPP' relative to current offense
            $table->unsignedTinyInteger('series_yardline')->nullable(); // 1..50
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            //
        });
    }
};
