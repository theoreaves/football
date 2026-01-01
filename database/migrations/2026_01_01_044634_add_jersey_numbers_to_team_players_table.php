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
        Schema::table('team_players', function (Blueprint $table) {
            $table->unsignedTinyInteger('jersey_number')->nullable()->after('team_year');

            // Unique per team + year (so you can re-use numbers across different years)
            $table->unique(['team_id', 'team_year', 'jersey_number'], 'tp_team_year_jersey_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_players', function (Blueprint $table) {
            //
        });
    }
};
