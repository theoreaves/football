<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('team_color1', 7)->nullable()->after('name'); // e.g. #1A2B3C
            $table->string('team_color2', 7)->nullable()->after('team_color1');

            $table->string('team_logo')->nullable()->after('team_color2');
            $table->string('helmet_logo_right')->nullable()->after('team_logo');
            $table->string('helmet_logo_left')->nullable()->after('helmet_logo_right');
            $table->string('midfield_logo')->nullable()->after('helmet_logo_left');
            $table->string('endzone_logo_right')->nullable()->after('midfield_logo');
            $table->string('endzone_logo_left')->nullable()->after('endzone_logo_right');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'team_color1','team_color2',
                'team_logo','helmet_logo_right','helmet_logo_left',
                'midfield_logo','endzone_logo_right','endzone_logo_left',
            ]);
        });
    }
};
