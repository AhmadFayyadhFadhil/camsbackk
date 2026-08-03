<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->tinyIncrements('id');
            $table->string('kode_shift', 5)->unique(); // S1|S2|S3|SN
            $table->string('nama_shift', 50);
            $table->time('jam_mulai');   // HH:MM:SS
            $table->time('jam_selesai'); // HH:MM:SS
            $table->boolean('is_overnight')->default(false); // true untuk S3 (22:00-06:00)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
