<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'supervisor_level')) {
            Schema::table('users', function (Blueprint $table) {
                // head | verifier | operational (hanya bermakna untuk role supervisor)
                $table->string('supervisor_level', 20)->nullable()->index();
                // Status ketersediaan (bertugas / tidak bertugas) - dipakai untuk Supervisor Verifikator
                $table->boolean('is_on_duty')->default(true);
            });
        }

        if (!Schema::hasTable('verifier_duty_logs')) {
            Schema::create('verifier_duty_logs', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
                $table->uuid('user_id');                 // verifikator yang statusnya diubah
                $table->boolean('is_on_duty');           // status baru
                $table->text('reason')->nullable();
                $table->uuid('changed_by')->nullable();  // pengguna yang mengubah
                $table->timestamp('changed_at');
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();
                $table->index(['user_id', 'changed_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('verifier_duty_logs');

        if (Schema::hasColumn('users', 'supervisor_level')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(['supervisor_level', 'is_on_duty']);
            });
        }
    }
};
