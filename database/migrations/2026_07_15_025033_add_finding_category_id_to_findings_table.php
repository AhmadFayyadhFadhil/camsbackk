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
        Schema::table('findings', function (Blueprint $table) {
            $table->uuid('finding_category_id')->nullable()->after('room_id');
            $table->foreign('finding_category_id')->references('id')->on('finding_categories')->nullOnDelete();
        });

        // Set default category 'Lainnya' untuk findings yang sudah ada
        $otherCategory = \Illuminate\Support\Facades\DB::table('finding_categories')
            ->where('kode_kategori', 'OTHER')
            ->first();

        if ($otherCategory) {
            \Illuminate\Support\Facades\DB::table('findings')->update([
                'finding_category_id' => $otherCategory->id,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->dropForeign(['finding_category_id']);
            $table->dropColumn('finding_category_id');
        });
    }
};
