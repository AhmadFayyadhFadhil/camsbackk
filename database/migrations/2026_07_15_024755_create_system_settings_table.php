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
        Schema::create('system_settings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->string('key')->unique();
            $table->text('value');
            $table->string('type', 20)->default('string');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Seed data default
        DB::table('system_settings')->insert([
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'key' => 'buffer_shift_minutes',
                'value' => '30',
                'type' => 'integer',
                'description' => 'Toleransi kedatangan shift CS untuk memindai QR Code (dalam menit)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'key' => 'escalation_pic_timeout_minutes',
                'value' => '120',
                'type' => 'integer',
                'description' => 'Durasi toleransi laporan tak terverifikasi oleh PIC sebelum dieskalasikan ke Supervisor (dalam menit)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'key' => 'task_reminder_before_end_minutes',
                'value' => '60',
                'type' => 'integer',
                'description' => 'Durasi sisa waktu shift CS sebelum notifikasi pengingat otomatis dikirim (dalam menit)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'key' => 'geofence_verification_enabled',
                'value' => 'false',
                'type' => 'boolean',
                'description' => 'Status aktif verifikasi jarak GPS (true/false) untuk mencocokkan koordinat CS dengan koordinat gedung',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'key' => 'geofence_allowed_distance_meters',
                'value' => '50',
                'type' => 'integer',
                'description' => 'Jarak radius maksimal (dalam meter) yang diperbolehkan saat CS mensubmit pengerjaan tugas dari koordinat gedung',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
