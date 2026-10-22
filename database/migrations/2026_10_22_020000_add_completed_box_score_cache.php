<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exhibitions', fn (Blueprint $table) => $table->json('box_score_cache')->nullable());
    }

    public function down(): void
    {
        Schema::table('exhibitions', fn (Blueprint $table) => $table->dropColumn('box_score_cache'));
    }
};
