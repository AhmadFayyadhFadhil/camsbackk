<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('schedule_id');
            $table->uuid('room_id');
            $table->uuid('cs_user_id')->nullable();
            $table->unsignedTinyInteger('shift_id');
            $table->date('tanggal_task');
            $table->enum('status', ['pending','in_progress','waiting_verification',
                                    'completed','rejected','overdue'])->default('pending');
            $table->timestamp('due_datetime');
            $table->timestamps();

            $table->foreign('schedule_id')->references('id')->on('schedules');
            $table->foreign('room_id')->references('id')->on('rooms');
            $table->foreign('cs_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('shift_id')->references('id')->on('shifts');
            $table->unique(['schedule_id', 'tanggal_task']);
            $table->index(['tanggal_task', 'status']);
            $table->index(['cs_user_id', 'tanggal_task']);
            $table->index(['room_id', 'tanggal_task']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
