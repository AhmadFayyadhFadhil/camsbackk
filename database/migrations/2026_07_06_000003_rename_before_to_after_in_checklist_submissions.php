<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->renameColumn('foto_before_1', 'foto_after_3');
            $table->renameColumn('foto_before_1_mime', 'foto_after_3_mime');
            $table->renameColumn('foto_before_2', 'foto_after_4');
            $table->renameColumn('foto_before_2_mime', 'foto_after_4_mime');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->renameColumn('foto_after_3', 'foto_before_1');
            $table->renameColumn('foto_after_3_mime', 'foto_before_1_mime');
            $table->renameColumn('foto_after_4', 'foto_before_2');
            $table->renameColumn('foto_after_4_mime', 'foto_before_2_mime');
        });
    }
};
