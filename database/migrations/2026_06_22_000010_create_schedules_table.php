<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('room_id');
            $table->uuid('checklist_item_id');
            $table->unsignedTinyInteger('shift_id');
            $table->enum('frekuensi', ['harian', 'mingguan', 'bulanan']);
            $table->tinyInteger('hari_minggu')->nullable();   // 0=Minggu s/d 6=Sabtu
            $table->tinyInteger('tanggal_bulan')->nullable(); // 1-31
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('room_id')->references('id')->on('rooms')->onDelete('cascade');
            $table->foreign('checklist_item_id')->references('id')->on('checklist_items');
            $table->foreign('shift_id')->references('id')->on('shifts');
            $table->unique(['room_id','checklist_item_id','shift_id','frekuensi'], 'unique_schedule');
            $table->index(['room_id', 'shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
