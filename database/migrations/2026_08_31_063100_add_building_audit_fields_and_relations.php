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
        // 1. Tambahkan kolom jadwal audit pada tabel buildings jika belum ada
        if (!Schema::hasColumn('buildings', 'asset_audit_interval')) {
            Schema::table('buildings', function (Blueprint $table) {
                $table->string('asset_audit_interval')->default('bimonthly');
                $table->unsignedSmallInteger('asset_audit_interval_days')->default(60);
                $table->dateTime('last_asset_audit_at')->nullable();
                $table->date('next_asset_audit_due')->nullable();
            });
        }

        // 2. Modifikasi room_asset_audits agar mendukung building_id dan room_id opsional
        Schema::table('room_asset_audits', function (Blueprint $table) {
            if (!Schema::hasColumn('room_asset_audits', 'building_id')) {
                $table->uuid('building_id')->nullable()->after('id');
                $table->foreign('building_id')->references('id')->on('buildings')->onDelete('cascade');
            }
            $table->uuid('room_id')->nullable()->change();
        });

        // 3. Tambahkan room_id pada room_asset_audit_items agar item audit tercatat di ruangan mana
        Schema::table('room_asset_audit_items', function (Blueprint $table) {
            if (!Schema::hasColumn('room_asset_audit_items', 'room_id')) {
                $table->uuid('room_id')->nullable()->after('room_asset_audit_id');
                $table->string('nama_ruangan_snapshot')->nullable()->after('room_id');
                $table->foreign('room_id')->references('id')->on('rooms')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_asset_audit_items', function (Blueprint $table) {
            if (Schema::hasColumn('room_asset_audit_items', 'room_id')) {
                $table->dropForeign(['room_id']);
                $table->dropColumn(['room_id', 'nama_ruangan_snapshot']);
            }
        });

        Schema::table('room_asset_audits', function (Blueprint $table) {
            if (Schema::hasColumn('room_asset_audits', 'building_id')) {
                $table->dropForeign(['building_id']);
                $table->dropColumn(['building_id']);
            }
        });

        Schema::table('buildings', function (Blueprint $table) {
            $table->dropColumn([
                'asset_audit_interval',
                'asset_audit_interval_days',
                'last_asset_audit_at',
                'next_asset_audit_due',
            ]);
        });
    }
};
