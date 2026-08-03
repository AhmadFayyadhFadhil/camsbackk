<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('task_id')->unique();
            $table->uuid('cs_user_id');
            $table->timestamp('submitted_at');
            $table->unsignedTinyInteger('resubmit_count')->default(0);
            $table->string('scan_token_used', 36);
            $table->text('catatan_cs')->nullable();
            $table->enum('status', ['submitted','approved','rejected'])->default('submitted');
            $table->mediumText('foto_before')->nullable(); // temporary, converted below
            $table->string('foto_before_mime', 30)->nullable();
            $table->mediumText('foto_after')->nullable(); // temporary, converted below
            $table->string('foto_after_mime', 30)->nullable();
            $table->timestamps();

            $table->foreign('task_id')->references('id')->on('tasks');
            $table->foreign('cs_user_id')->references('id')->on('users');
            $table->index(['cs_user_id', 'created_at']);
        });

        // Convert to MEDIUMBLOB
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE checklist_submissions MODIFY foto_before MEDIUMBLOB NULL");
            DB::statement("ALTER TABLE checklist_submissions MODIFY foto_after  MEDIUMBLOB NULL");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_submissions');
    }
};
