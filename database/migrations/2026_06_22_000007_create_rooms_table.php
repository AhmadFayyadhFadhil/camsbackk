<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('building_id');
            $table->string('kode_ruangan', 30);
            $table->string('nama_ruangan');
            $table->string('lantai', 10)->nullable();
            $table->uuid('pic_user_id')->nullable();
            $table->uuid('qr_code_token')->nullable()->unique();
            $table->mediumText('qr_code_image')->nullable(); // temporary, converted below
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('building_id')->references('id')->on('buildings')->onDelete('cascade');
            $table->foreign('pic_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['building_id', 'kode_ruangan']);
            $table->index('building_id');
            $table->index('pic_user_id');
        });

        // Convert to MEDIUMBLOB
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE rooms MODIFY qr_code_image MEDIUMBLOB NULL");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
