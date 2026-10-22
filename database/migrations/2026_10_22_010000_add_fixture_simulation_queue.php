<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('season_fixtures', function (Blueprint $table) {
            $table->string('simulation_status', 16)->nullable();
            $table->uuid('simulation_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('season_fixtures', fn (Blueprint $table) => $table->dropColumn(['simulation_status', 'simulation_token']));
    }
};
