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
        Schema::table('players', function (Blueprint $table) {
            $table->string('espn_id', 30)->nullable()->after('sleeper_id')->index();
            $table->string('high_school', 30)->nullable()->after('sleeper_id')->index();
            $table->string('college', 30)->nullable()->after('sleeper_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            //
        });
    }
};
