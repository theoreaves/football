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
                $table->boolean("uniform_{$venue}_name_enabled")->default(false);
                $table->string("uniform_{$venue}_name_color", 7)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            foreach (['home', 'away'] as $venue) {
                $table->dropColumn(["uniform_{$venue}_name_enabled", "uniform_{$venue}_name_color"]);
            }
        });
    }
};
