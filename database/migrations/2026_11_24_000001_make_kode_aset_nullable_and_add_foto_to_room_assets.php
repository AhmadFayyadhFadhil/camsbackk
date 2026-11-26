<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_assets', function (Blueprint $table) {
            $table->string('kode_aset', 100)->nullable()->change();
            if (!Schema::hasColumn('room_assets', 'foto_aset')) {
                $table->string('foto_aset')->nullable()->after('jumlah');
            }
        });
    }

    public function down(): void
    {
        Schema::table('room_assets', function (Blueprint $table) {
            if (Schema::hasColumn('room_assets', 'foto_aset')) {
                $table->dropColumn('foto_aset');
            }
        });
    }
};
