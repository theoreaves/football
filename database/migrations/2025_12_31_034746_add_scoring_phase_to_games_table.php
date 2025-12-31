<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->enum('phase', ['NORMAL', 'TRY', 'KICKOFF', 'PUNT', 'INT', 'FUMBLE', 'FIELDGOAL'])->default('NORMAL');
            $table->string('try_team')->nullable(); // HOME/AWAY
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['phase', 'try_team']);
        });
    }
};
