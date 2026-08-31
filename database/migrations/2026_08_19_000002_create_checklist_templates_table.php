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
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nama_template');
            $table->text('deskripsi')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('checklist_template_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('checklist_template_id');
            $table->string('nama_item');
            $table->text('deskripsi')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('checklist_template_id')
                ->references('id')
                ->on('checklist_templates')
                ->onDelete('cascade');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->uuid('checklist_template_id')->nullable()->after('pic_user_id');
            $table->foreign('checklist_template_id')
                ->references('id')
                ->on('checklist_templates')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropForeign(['checklist_template_id']);
            $table->dropColumn('checklist_template_id');
        });

        Schema::dropIfExists('checklist_template_items');
        Schema::dropIfExists('checklist_templates');
    }
};
