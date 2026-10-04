<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worlds', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->change();
        });
        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique(['abbr']);
            $table->unique(['world_id', 'abbr']);
        });
        Schema::table('players', function (Blueprint $table) {
            $table->dropUnique(['sleeper_id']);
            $table->unique(['world_id', 'sleeper_id']);
        });
        Schema::create('local_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('current_world_id')->nullable()->constrained('worlds')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Ownership stays nullable so rolling back does not invalidate local saved games.
        Schema::dropIfExists('local_settings');
        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique(['world_id', 'abbr']);
            $table->unique('abbr');
        });
        Schema::table('players', function (Blueprint $table) {
            $table->dropUnique(['world_id', 'sleeper_id']);
            $table->unique('sleeper_id');
        });
    }
};
