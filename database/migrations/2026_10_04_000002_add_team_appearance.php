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
                foreach (['helmet', 'shirt', 'pants', 'socks'] as $part) {
                    $table->string("uniform_{$venue}_{$part}", 7)->nullable();
                }
            }
            $table->string('endzone_text', 40)->nullable();
            $table->string('endzone_background', 7)->nullable();
            $table->string('endzone_text_color', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            foreach (['home', 'away'] as $venue) {
                foreach (['helmet', 'shirt', 'pants', 'socks'] as $part) {
                    $table->dropColumn("uniform_{$venue}_{$part}");
                }
            }
            $table->dropColumn(['endzone_text', 'endzone_background', 'endzone_text_color']);
        });
    }
};
