<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cs_assignments', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['shift_id']);
            }
        });

        Schema::table('cs_assignments', function (Blueprint $table) {
            $table->unsignedTinyInteger('shift_id')->nullable()->change();
        });

        Schema::table('cs_assignments', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('shift_id')->references('id')->on('shifts');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cs_assignments', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['shift_id']);
            }
        });

        Schema::table('cs_assignments', function (Blueprint $table) {
            $table->unsignedTinyInteger('shift_id')->nullable(false)->change();
        });

        Schema::table('cs_assignments', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('shift_id')->references('id')->on('shifts');
            }
        });
    }
};
