<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename kolom 'merk' (string) → 'jumlah' (unsignedSmallInteger, nullable, default 1)
     */
    public function up(): void
    {
        Schema::table('room_assets', function (Blueprint $table) {
            $table->dropColumn('merk');
        });

        Schema::table('room_assets', function (Blueprint $table) {
            $table->unsignedSmallInteger('jumlah')->nullable()->default(1)->after('kode_aset');
        });
    }

    public function down(): void
    {
        Schema::table('room_assets', function (Blueprint $table) {
            $table->dropColumn('jumlah');
        });

        Schema::table('room_assets', function (Blueprint $table) {
            $table->string('merk')->nullable()->after('kode_aset');
        });
    }
};
