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
        Schema::create('room_asset_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('room_id');
            $table->uuid('auditor_id');
            $table->uuid('verified_by')->nullable();
            $table->string('periode', 30); // e.g. "2026-08" atau "Agustus 2026"
            $table->date('audit_date');
            $table->string('status', 30)->default('submitted'); // submitted, approved, rejected
            $table->unsignedInteger('total_expected')->default(0);
            $table->unsignedInteger('total_actual')->default(0);
            $table->boolean('has_discrepancy')->default(false);
            $table->text('notes')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('room_id')->references('id')->on('rooms')->onDelete('cascade');
            $table->foreign('auditor_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('verified_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('room_asset_audit_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('room_asset_audit_id');
            $table->uuid('room_asset_id');
            $table->string('nama_aset_snapshot')->nullable();
            $table->string('kode_aset_snapshot')->nullable();
            $table->unsignedSmallInteger('jumlah_expected')->default(1);
            $table->unsignedSmallInteger('jumlah_actual')->default(1);
            $table->string('kondisi', 30)->default('good'); // good, damaged, missing
            $table->string('foto_bukti')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->foreign('room_asset_audit_id')->references('id')->on('room_asset_audits')->onDelete('cascade');
            $table->foreign('room_asset_id')->references('id')->on('room_assets')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_asset_audit_items');
        Schema::dropIfExists('room_asset_audits');
    }
};
