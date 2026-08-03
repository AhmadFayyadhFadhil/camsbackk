<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_results', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('submission_id');
            $table->uuid('checklist_item_id');
            $table->boolean('is_done')->default(false);
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->foreign('submission_id')->references('id')->on('checklist_submissions')->onDelete('cascade');
            $table->foreign('checklist_item_id')->references('id')->on('checklist_items');
            $table->index('submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_results');
    }
};
