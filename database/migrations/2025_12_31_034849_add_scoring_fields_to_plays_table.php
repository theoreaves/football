<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('plays', function (Blueprint $table) {
            $table->string('result')->nullable(); // GOOD, FAIL, etc.
            $table->unsignedTinyInteger('points')->default(0);
            $table->json('meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plays', function (Blueprint $table) {
            $table->dropColumn(['result', 'points', 'meta']);
        });
    }
};
