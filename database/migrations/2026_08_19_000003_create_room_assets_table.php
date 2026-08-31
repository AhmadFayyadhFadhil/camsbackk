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
        Schema::create('room_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('room_id');
            $table->string('nama_aset');
            $table->string('kode_aset')->unique();
            $table->string('merk')->nullable();
            $table->string('status')->default('active'); // active, damaged, repaired
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('room_id')
                ->references('id')
                ->on('rooms')
                ->onDelete('cascade');
        });

        Schema::table('findings', function (Blueprint $table) {
            $table->uuid('room_asset_id')->nullable()->after('room_id');
            $table->foreign('room_asset_id')
                ->references('id')
                ->on('room_assets')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->dropForeign(['room_asset_id']);
            $table->dropColumn('room_asset_id');
        });

        Schema::dropIfExists('room_assets');
    }
};
