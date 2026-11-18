<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Kolom workflow pada checklist_submissions (timestamp terpisah per tahap)
        if (!Schema::hasColumn('checklist_submissions', 'verification_stage')) {
            Schema::table('checklist_submissions', function (Blueprint $table) {
                $table->string('verification_stage', 30)->nullable()->index();
                $table->uuid('verifier_id')->nullable();                 // penangani tahap verifikasi saat ini
                $table->timestamp('verification_deadline_at')->nullable()->index(); // submitted_at + SLA
                $table->timestamp('verified_at')->nullable();            // verifikasi Supervisor
                $table->uuid('verified_by')->nullable();
                $table->timestamp('redirected_at')->nullable();          // pengalihan terakhir ke Winaryo
                $table->string('redirect_reason', 30)->nullable();
                $table->timestamp('final_approved_at')->nullable();      // approval akhir
                $table->uuid('final_approved_by')->nullable();

                $table->foreign('verifier_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('verified_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('final_approved_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        // 2. Tahap pada tabel verifications (null = data lama / legacy satu tahap)
        if (!Schema::hasColumn('verifications', 'stage')) {
            Schema::table('verifications', function (Blueprint $table) {
                $table->string('stage', 30)->nullable(); // verification | final_approval
            });
        }

        // 3. Riwayat pengalihan laporan
        if (!Schema::hasTable('submission_redirects')) {
            Schema::create('submission_redirects', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
                $table->uuid('submission_id');
                $table->uuid('from_user_id')->nullable();
                $table->uuid('to_user_id')->nullable();
                $table->string('reason', 30);              // verifier_off | sla_overdue | verifier_unavailable
                $table->uuid('redirected_by')->nullable(); // null = sistem (scheduler / submit otomatis)
                $table->text('note')->nullable();
                $table->timestamp('redirected_at');
                $table->timestamps();

                $table->foreign('submission_id')->references('id')->on('checklist_submissions')->cascadeOnDelete();
                $table->foreign('from_user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('to_user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('redirected_by')->references('id')->on('users')->nullOnDelete();
                $table->index(['submission_id', 'redirected_at']);
            });
        }

        // 4. Konfigurasi SLA (24 jam kalender). Aman dijalankan ulang; tidak menimpa nilai yang sudah diubah admin.
        $hours = (int) config('cams.verification.sla_hours', 24);
        $exists = DB::table('system_settings')->where('key', 'verification_sla_hours')->exists();
        if (!$exists) {
            DB::table('system_settings')->insert([
                'id' => (string) Str::uuid(),
                'key' => 'verification_sla_hours',
                'value' => (string) $hours,
                'type' => 'integer',
                'description' => 'SLA verifikasi Checklist Harian oleh Supervisor Verifikator (jam kalender nonstop sejak CS submit). Lewat batas ini laporan otomatis dialihkan ke Supervisor tertinggi.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // SLA lama 120 menit (PIC) tidak lagi dipakai workflow Checklist Harian. Baris dipertahankan agar data tidak hilang.
        DB::table('system_settings')->where('key', 'escalation_pic_timeout_minutes')->update([
            'description' => '[TIDAK DIPAKAI] SLA lama 120 menit verifikasi PIC. Digantikan verification_sla_hours (24 jam kalender).',
            'updated_at' => now(),
        ]);

        // 5. Backfill laporan yang sedang antre (status submitted) agar ikut SLA 24 jam.
        //    Penugasan verifikator dilakukan oleh scheduler (cams:check-escalations) karena identitas akun
        //    Pak Kusnan / Pak Winaryo ditentukan lewat seeder, bukan migration.
        DB::table('checklist_submissions')
            ->where('status', 'submitted')
            ->whereNull('verification_stage')
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($hours) {
                foreach ($rows as $row) {
                    DB::table('checklist_submissions')->where('id', $row->id)->update([
                        'verification_stage' => 'pending_verification',
                        'verification_deadline_at' => Carbon::parse($row->submitted_at)->addHours($hours),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_redirects');

        if (Schema::hasColumn('verifications', 'stage')) {
            Schema::table('verifications', function (Blueprint $table) {
                $table->dropColumn('stage');
            });
        }

        if (Schema::hasColumn('checklist_submissions', 'verification_stage')) {
            Schema::table('checklist_submissions', function (Blueprint $table) {
                $table->dropForeign(['verifier_id']);
                $table->dropForeign(['verified_by']);
                $table->dropForeign(['final_approved_by']);
                $table->dropIndex(['verification_stage']);
                $table->dropIndex(['verification_deadline_at']);
                $table->dropColumn([
                    'verification_stage', 'verifier_id', 'verification_deadline_at',
                    'verified_at', 'verified_by', 'redirected_at', 'redirect_reason',
                    'final_approved_at', 'final_approved_by',
                ]);
            });
        }

        DB::table('system_settings')->where('key', 'verification_sla_hours')->delete();
    }
};
