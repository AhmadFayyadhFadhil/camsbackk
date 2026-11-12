<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Master area ruangan (LOBBY, OFFICE, RUANG MEETING, TOILET, dst.)
        if (!Schema::hasTable('room_areas')) {
            Schema::create('room_areas', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
                $table->string('nomor', 10);                    // penomoran area pada sumber data (mis. 1, 1.2, 1.3)
                $table->string('nama_area', 100)->unique();
                $table->unsignedSmallInteger('urutan')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('urutan');
            });
        }

        // 2. Relasi ruangan -> area (nullable agar data ruangan lama tetap valid)
        Schema::table('rooms', function (Blueprint $table) {
            if (!Schema::hasColumn('rooms', 'room_area_id')) {
                $table->uuid('room_area_id')->nullable()->after('building_id');
            }
            if (!Schema::hasColumn('rooms', 'urutan_area')) {
                $table->unsignedSmallInteger('urutan_area')->nullable();
            }
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->foreign('room_area_id')->references('id')->on('room_areas')->nullOnDelete();
            $table->index('room_area_id');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropForeign(['room_area_id']);
            $table->dropIndex(['room_area_id']);
            $table->dropColumn(['room_area_id', 'urutan_area']);
        });

        Schema::dropIfExists('room_areas');
    }
};
