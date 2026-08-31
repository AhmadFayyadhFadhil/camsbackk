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
        Schema::create('cleaning_materials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nama_material');
            $table->string('jenis'); // chemical, tool
            $table->string('kode_material')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('submission_materials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('submission_id');
            $table->uuid('cleaning_material_id');
            $table->timestamps();

            $table->foreign('submission_id')
                ->references('id')
                ->on('checklist_submissions')
                ->onDelete('cascade');

            $table->foreign('cleaning_material_id')
                ->references('id')
                ->on('cleaning_materials')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submission_materials');
        Schema::dropIfExists('cleaning_materials');
    }
};
