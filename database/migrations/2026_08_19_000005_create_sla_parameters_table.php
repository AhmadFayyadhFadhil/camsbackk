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
        Schema::create('sla_parameters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nama_parameter');
            $table->text('deskripsi')->nullable();
            $table->string('tipe_penilaian')->default('scale_1_5'); // scale_1_5, yes_no
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('verification_sla_ratings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('verification_id');
            $table->uuid('sla_parameter_id');
            $table->string('nilai'); // e.g. "4", "yes"
            $table->timestamps();

            $table->foreign('verification_id')
                ->references('id')
                ->on('verifications')
                ->onDelete('cascade');

            $table->foreign('sla_parameter_id')
                ->references('id')
                ->on('sla_parameters')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verification_sla_ratings');
        Schema::dropIfExists('sla_parameters');
    }
};
