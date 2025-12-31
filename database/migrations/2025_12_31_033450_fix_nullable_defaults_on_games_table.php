<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Backfill existing rows
        DB::table('games')->whereNull('pos_side')->update(['pos_side' => 'OWN']);
        DB::table('games')->whereNull('pos_yardline')->update(['pos_yardline' => 25]);
        DB::table('games')->whereNull('down')->update(['down' => 1]);
        DB::table('games')->whereNull('to_go')->update(['to_go' => 10]);
        DB::table('games')->whereNull('possession')->update(['possession' => 'HOME']);

        Schema::table('games', function (Blueprint $table) {
            // Make them NOT NULL with defaults
            $table->string('possession')->default('HOME')->nullable(false)->change();
            $table->enum('pos_side', ['OWN', 'OPP'])->default('OWN')->nullable(false)->change();
            $table->unsignedTinyInteger('pos_yardline')->default(25)->nullable(false)->change();
            $table->unsignedTinyInteger('down')->default(1)->nullable(false)->change();
            $table->unsignedTinyInteger('to_go')->default(10)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // optional rollback left empty intentionally
    }
};
