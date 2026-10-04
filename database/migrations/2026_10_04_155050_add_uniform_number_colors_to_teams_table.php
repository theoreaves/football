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
        Schema::table('teams', function (Blueprint $table) {
            foreach (['home', 'away'] as $venue) {
                $table->string("uniform_{$venue}_number", 7)->nullable();
                $table->string("uniform_{$venue}_number_outline", 7)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            foreach (['home', 'away'] as $venue) {
                $table->dropColumn(["uniform_{$venue}_number", "uniform_{$venue}_number_outline"]);
            }
        });
    }
};
