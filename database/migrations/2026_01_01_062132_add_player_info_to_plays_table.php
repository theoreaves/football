<?php
// database/migrations/xxxx_xx_xx_xxxxxx_add_participants_to_plays.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('plays', function (Blueprint $table) {
            $table->unsignedBigInteger('qb_team_player_id')->nullable();
            $table->unsignedBigInteger('ballcarrier_team_player_id')->nullable();
            $table->unsignedBigInteger('receiver_team_player_id')->nullable();
            $table->unsignedBigInteger('tackled_by_team_player_id')->nullable();
            $table->unsignedBigInteger('intercepted_by_team_player_id')->nullable();
            $table->unsignedBigInteger('fumble_recovered_by_team_player_id')->nullable();

            // If you want actual FK constraints:
            $table->foreign('qb_team_player_id')->references('id')->on('team_players')->nullOnDelete();
            $table->foreign('ballcarrier_team_player_id')->references('id')->on('team_players')->nullOnDelete();
            $table->foreign('receiver_team_player_id')->references('id')->on('team_players')->nullOnDelete();
            $table->foreign('tackled_by_team_player_id')->references('id')->on('team_players')->nullOnDelete();
            $table->foreign('intercepted_by_team_player_id')->references('id')->on('team_players')->nullOnDelete();
            $table->foreign('fumble_recovered_by_team_player_id')->references('id')->on('team_players')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('plays', function (Blueprint $table) {
            $table->dropForeign(['qb_team_player_id']);
            $table->dropForeign(['ballcarrier_team_player_id']);
            $table->dropForeign(['receiver_team_player_id']);
            $table->dropForeign(['tackled_by_team_player_id']);
            $table->dropForeign(['intercepted_by_team_player_id']);
            $table->dropForeign(['fumble_recovered_by_team_player_id']);

            $table->dropColumn([
                'qb_team_player_id',
                'ballcarrier_team_player_id',
                'receiver_team_player_id',
                'tackled_by_team_player_id',
                'intercepted_by_team_player_id',
                'fumble_recovered_by_team_player_id',
            ]);
        });
    }
};
