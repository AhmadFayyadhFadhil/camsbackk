<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('room_id');
            $table->uuid('reported_by');
            $table->text('deskripsi');
            $table->enum('prioritas', ['low','medium','high'])->default('medium');
            $table->enum('status', ['open','in_progress','resolved'])->default('open');
            $table->mediumText('foto_finding')->nullable(); // temporary, converted below
            $table->string('foto_finding_mime', 30)->nullable();
            $table->date('deadline_perbaikan')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('room_id')->references('id')->on('rooms');
            $table->foreign('reported_by')->references('id')->on('users');
            $table->index(['room_id', 'status']);
        });

        // Convert to MEDIUMBLOB
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE findings MODIFY foto_finding MEDIUMBLOB NULL");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};
