<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('submission_id');
            $table->uuid('verified_by');
            $table->enum('role_verifier', ['pic', 'supervisor']);
            $table->enum('status', ['approved', 'rejected']);
            $table->text('catatan_perbaikan')->nullable();
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->foreign('submission_id')->references('id')->on('checklist_submissions');
            $table->foreign('verified_by')->references('id')->on('users');
            $table->index('submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications');
    }
};
