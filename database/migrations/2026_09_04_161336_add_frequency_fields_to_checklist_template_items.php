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
        Schema::table('checklist_template_items', function (Blueprint $table) {
            $table->enum('frekuensi', ['harian', 'mingguan', 'bulanan'])->default('harian')->after('deskripsi');
            $table->unsignedTinyInteger('hari_minggu')->nullable()->after('frekuensi')->comment('0=Minggu, 1=Senin, ..., 6=Sabtu');
            $table->unsignedTinyInteger('tanggal_bulan')->nullable()->after('hari_minggu')->comment('1-31');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checklist_template_items', function (Blueprint $table) {
            $table->dropColumn(['frekuensi', 'hari_minggu', 'tanggal_bulan']);
        });
    }
};
