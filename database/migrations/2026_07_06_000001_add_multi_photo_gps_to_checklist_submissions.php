<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->binary('foto_before_1')->nullable();
            $table->string('foto_before_1_mime', 30)->nullable();
            $table->binary('foto_before_2')->nullable();
            $table->string('foto_before_2_mime', 30)->nullable();
            $table->binary('foto_after_1')->nullable();
            $table->string('foto_after_1_mime', 30)->nullable();
            $table->binary('foto_after_2')->nullable();
            $table->string('foto_after_2_mime', 30)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->float('gps_accuracy')->nullable();
            $table->timestamp('gps_captured_at')->nullable();
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE checklist_submissions MODIFY foto_before_1 LONGBLOB NULL');
            DB::statement('ALTER TABLE checklist_submissions MODIFY foto_before_2 LONGBLOB NULL');
            DB::statement('ALTER TABLE checklist_submissions MODIFY foto_after_1 LONGBLOB NULL');
            DB::statement('ALTER TABLE checklist_submissions MODIFY foto_after_2 LONGBLOB NULL');
        }
    }

    public function down(): void
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->dropColumn([
                'foto_before_1',
                'foto_before_1_mime',
                'foto_before_2',
                'foto_before_2_mime',
                'foto_after_1',
                'foto_after_1_mime',
                'foto_after_2',
                'foto_after_2_mime',
                'latitude',
                'longitude',
                'gps_accuracy',
                'gps_captured_at',
            ]);
        });
    }
};
