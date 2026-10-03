<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worlds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('world_user', function (Blueprint $table) {
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('owner');
            $table->timestamps();
            $table->primary(['world_id', 'user_id']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->foreignId('current_world_id')->nullable()->constrained('worlds')->nullOnDelete());
        Schema::create('leagues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->foreignId('league_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('current_week')->default(1);
            $table->string('phase')->default('preseason');
            $table->timestamps();
            $table->unique(['league_id', 'year']);
        });
        foreach (['teams', 'players', 'games'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('world_id')->nullable()->constrained()->restrictOnDelete());
        }
        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('season_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('week')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('games', fn (Blueprint $table) => $table->dropConstrainedForeignId('season_id'));
        Schema::table('games', fn (Blueprint $table) => $table->dropColumn('week'));
        foreach (['games', 'players', 'teams'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('world_id'));
        }
        Schema::dropIfExists('seasons');
        Schema::dropIfExists('leagues');
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_world_id'));
        Schema::dropIfExists('world_user');
        Schema::dropIfExists('worlds');
    }
};
