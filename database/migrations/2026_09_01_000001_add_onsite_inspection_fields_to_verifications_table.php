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
        Schema::table('verifications', function (Blueprint $table) {
            $table->string('foto_inspeksi_path')->nullable()->after('catatan_perbaikan');
            $table->timestamp('qr_scanned_at')->nullable()->after('foto_inspeksi_path');
            $table->decimal('latitude', 10, 8)->nullable()->after('qr_scanned_at');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            $table->boolean('is_onsite_verified')->default(true)->after('longitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('verifications', function (Blueprint $table) {
            $table->dropColumn([
                'foto_inspeksi_path',
                'qr_scanned_at',
                'latitude',
                'longitude',
                'is_onsite_verified',
            ]);
        });
    }
};
