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
        Schema::table('buildings', function (Blueprint $table) {
            if (!Schema::hasColumn('buildings', 'radius_meter')) {
                $table->integer('radius_meter')->default(250)->after('longitude');
            }
        });

        // Set default radius untuk semua gedung yang sudah ada
        \Illuminate\Support\Facades\DB::table('buildings')
            ->whereNull('radius_meter')
            ->orWhere('radius_meter', 0)
            ->update([
                'radius_meter' => 250,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            if (Schema::hasColumn('buildings', 'radius_meter')) {
                $table->dropColumn('radius_meter');
            }
        });
    }
};
