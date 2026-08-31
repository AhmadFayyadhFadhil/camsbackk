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
        Schema::table('adhoc_tasks', function (Blueprint $table) {
            $table->string('task_type')->default('immediate')->after('priority'); // immediate, scheduled_event
            $table->dateTime('due_datetime')->nullable()->after('task_type'); // target selesai persiapan
            $table->dateTime('event_start_time')->nullable()->after('due_datetime'); // jam mulai acara / meeting
            $table->json('checklist_items')->nullable()->after('deskripsi'); // list persiapan
            $table->text('verification_notes')->nullable()->after('foto_bukti');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('adhoc_tasks', function (Blueprint $table) {
            $table->dropColumn([
                'task_type',
                'due_datetime',
                'event_start_time',
                'checklist_items',
                'verification_notes',
            ]);
        });
    }
};
