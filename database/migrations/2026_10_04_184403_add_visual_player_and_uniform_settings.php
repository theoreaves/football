<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedSmallInteger('height_inches')->nullable();
            $table->unsignedSmallInteger('weight_pounds')->nullable();
            $table->string('skin_tone', 7)->nullable();
        });
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('endzone_transparent')->default(false);
            foreach (['home', 'away'] as $venue) {
                foreach (['helmet', 'shoulder', 'pants'] as $part) {
                    $table->boolean("uniform_{$venue}_{$part}_stripe_enabled")->default(false);
                    $table->string("uniform_{$venue}_{$part}_stripe", 7)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('players', fn (Blueprint $table) => $table->dropColumn(['height_inches', 'weight_pounds', 'skin_tone']));
        Schema::table('teams', function (Blueprint $table) {
            $columns = ['endzone_transparent'];
            foreach (['home', 'away'] as $venue) {
                foreach (['helmet', 'shoulder', 'pants'] as $part) {
                    $columns[] = "uniform_{$venue}_{$part}_stripe_enabled";
                    $columns[] = "uniform_{$venue}_{$part}_stripe";
                }
            }
            $table->dropColumn($columns);
        });
    }
};
