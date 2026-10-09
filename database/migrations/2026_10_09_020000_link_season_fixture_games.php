<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('season_fixtures', function (Blueprint $table) {
            $table->foreignId('exhibition_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('home_score')->nullable();
            $table->unsignedSmallInteger('away_score')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('season_fixtures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exhibition_id');
            $table->dropColumn(['home_score', 'away_score']);
        });
    }
};
