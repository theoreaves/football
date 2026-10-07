<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('stadium_style', 40)->default('classic_oval');
            $table->string('stadium_seat_color', 7)->nullable();
            $table->string('stadium_wall_color', 7)->nullable();
            $table->string('stadium_roof_color', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn(['stadium_style', 'stadium_seat_color', 'stadium_wall_color', 'stadium_roof_color']));
    }
};
