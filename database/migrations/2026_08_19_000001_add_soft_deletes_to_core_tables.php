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
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('buildings', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('rooms', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
