<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->mediumText('foto_ob_1')->nullable()->after('foto_selesai_mime');
            $table->string('foto_ob_1_mime', 30)->nullable()->after('foto_ob_1');
            $table->mediumText('foto_ob_2')->nullable()->after('foto_ob_1_mime');
            $table->string('foto_ob_2_mime', 30)->nullable()->after('foto_ob_2');
            $table->mediumText('foto_ob_3')->nullable()->after('foto_ob_2_mime');
            $table->string('foto_ob_3_mime', 30)->nullable()->after('foto_ob_3');
            $table->mediumText('foto_ob_4')->nullable()->after('foto_ob_3_mime');
            $table->string('foto_ob_4_mime', 30)->nullable()->after('foto_ob_4');
        });

        // Convert text columns to MEDIUMBLOB for binary image storage
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE findings MODIFY foto_ob_1 MEDIUMBLOB NULL");
            DB::statement("ALTER TABLE findings MODIFY foto_ob_2 MEDIUMBLOB NULL");
            DB::statement("ALTER TABLE findings MODIFY foto_ob_3 MEDIUMBLOB NULL");
            DB::statement("ALTER TABLE findings MODIFY foto_ob_4 MEDIUMBLOB NULL");
        }
    }

    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->dropColumn([
                'foto_ob_1', 'foto_ob_1_mime',
                'foto_ob_2', 'foto_ob_2_mime',
                'foto_ob_3', 'foto_ob_3_mime',
                'foto_ob_4', 'foto_ob_4_mime',
            ]);
        });
    }
};
