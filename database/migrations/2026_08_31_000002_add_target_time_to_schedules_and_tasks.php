<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            if (!Schema::hasColumn('schedules', 'target_jam_mulai')) {
                $table->time('target_jam_mulai')->nullable()->after('tanggal_bulan');
            }
            if (!Schema::hasColumn('schedules', 'target_jam_selesai')) {
                $table->time('target_jam_selesai')->nullable()->after('target_jam_mulai');
            }
            if (!Schema::hasColumn('schedules', 'estimasi_durasi_menit')) {
                $table->integer('estimasi_durasi_menit')->nullable()->default(30)->after('target_jam_selesai');
            }
            if (!Schema::hasColumn('schedules', 'urutan')) {
                $table->integer('urutan')->nullable()->default(1)->after('estimasi_durasi_menit');
            }
        });

        Schema::table('tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('tasks', 'target_jam_mulai')) {
                $table->time('target_jam_mulai')->nullable()->after('tanggal_task');
            }
            if (!Schema::hasColumn('tasks', 'target_jam_selesai')) {
                $table->time('target_jam_selesai')->nullable()->after('target_jam_mulai');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn(['target_jam_mulai', 'target_jam_selesai', 'estimasi_durasi_menit', 'urutan']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['target_jam_mulai', 'target_jam_selesai']);
        });
    }
};
