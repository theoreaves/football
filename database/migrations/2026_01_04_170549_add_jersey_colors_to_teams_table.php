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
            $table->string('jersey_dark_primary', 7)->nullable()->after('team_color2'); // e.g. #1A2B3C
            $table->string('jersey_dark_outline', 7)->nullable()->after('jersey_dark_primary'); // e.g. #1A2B3C
            $table->string('jersey_dark_font', 7)->nullable()->after('jersey_dark_outline'); // e.g. #1A2B3C
            $table->string('jersey_white_primary', 7)->nullable()->after('jersey_dark_font'); // e.g. #1A2B3C
            $table->string('jersey_white_outline', 7)->nullable()->after('jersey_white_primary'); // e.g. #1A2B3C
            $table->string('jersey_white_font', 7)->nullable()->after('jersey_white_outline'); // e.g. #1A2B3C
            $table->boolean('wear_white_at_home')->default(false)->after('jersey_white_font');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            //
        });
    }
};
