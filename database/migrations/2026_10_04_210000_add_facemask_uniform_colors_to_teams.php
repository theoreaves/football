<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            foreach (['home', 'away'] as $venue) {
                $table->string("uniform_{$venue}_facemask", 7)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn(['uniform_home_facemask', 'uniform_away_facemask']));
    }
};
