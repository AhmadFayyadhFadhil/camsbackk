<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('adhoc_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('adhoc_tasks', 'foto_bukti_mime')) {
                $table->string('foto_bukti_mime', 30)->nullable()->after('foto_bukti');
            }
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE adhoc_tasks MODIFY foto_bukti MEDIUMBLOB NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('adhoc_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('adhoc_tasks', 'foto_bukti_mime')) {
                $table->dropColumn('foto_bukti_mime');
            }
        });
    }
};
