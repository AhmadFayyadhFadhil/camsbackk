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
        Schema::table('rooms', function (Blueprint $table) {
            if (!Schema::hasColumn('rooms', 'latitude')) {
                $table->decimal('latitude', 10, 8)->nullable()->after('is_active');
            }
            if (!Schema::hasColumn('rooms', 'longitude')) {
                $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            }
            if (!Schema::hasColumn('rooms', 'radius_meter')) {
                $table->integer('radius_meter')->nullable()->after('longitude');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('rooms', 'latitude')) $columns[] = 'latitude';
            if (Schema::hasColumn('rooms', 'longitude')) $columns[] = 'longitude';
            if (Schema::hasColumn('rooms', 'radius_meter')) $columns[] = 'radius_meter';
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
