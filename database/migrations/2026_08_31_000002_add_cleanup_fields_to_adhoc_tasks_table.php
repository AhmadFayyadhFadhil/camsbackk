<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('adhoc_tasks', function (Blueprint $table) {
            $table->boolean('requires_cleanup')->default(false)->after('task_type');
            $table->string('stage')->default('pending')->after('status'); // pending, setup_in_progress, setup_submitted, cleanup_in_progress, completed
            $table->binary('foto_bukti_cleanup')->nullable()->after('foto_bukti_mime');
            $table->string('foto_bukti_cleanup_mime')->nullable()->after('foto_bukti_cleanup');
            $table->timestamp('setup_submitted_at')->nullable()->after('submitted_at');
            $table->timestamp('cleanup_submitted_at')->nullable()->after('setup_submitted_at');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE adhoc_tasks MODIFY foto_bukti_cleanup MEDIUMBLOB NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('adhoc_tasks', function (Blueprint $table) {
            $table->dropColumn([
                'requires_cleanup',
                'stage',
                'foto_bukti_cleanup',
                'foto_bukti_cleanup_mime',
                'setup_submitted_at',
                'cleanup_submitted_at',
            ]);
        });
    }
};
