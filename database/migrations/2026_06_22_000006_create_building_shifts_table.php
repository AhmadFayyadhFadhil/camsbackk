<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('building_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('building_id');
            $table->unsignedTinyInteger('shift_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('building_id')->references('id')->on('buildings')->onDelete('cascade');
            $table->foreign('shift_id')->references('id')->on('shifts');
            $table->unique(['building_id', 'shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_shifts');
    }
};
