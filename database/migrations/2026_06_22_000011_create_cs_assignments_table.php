<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cs_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('cs_user_id');
            $table->uuid('building_id');
            $table->unsignedTinyInteger('shift_id');
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('cs_user_id')->references('id')->on('users');
            $table->foreign('building_id')->references('id')->on('buildings');
            $table->foreign('shift_id')->references('id')->on('shifts');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['cs_user_id', 'building_id', 'shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cs_assignments');
    }
};
