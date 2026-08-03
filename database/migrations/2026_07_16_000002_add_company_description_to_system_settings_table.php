<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $exists = DB::table('system_settings')->where('key', 'company_description')->exists();

        if (!$exists) {
            DB::table('system_settings')->insert([
                'id' => (string) Str::uuid(),
                'key' => 'company_description',
                'value' => 'Cleaning Activity Monitor',
                'type' => 'string',
                'description' => 'Deskripsi / tagline pendek perusahaan yang ditampilkan di bawah nama pada sidebar header',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_settings')->where('key', 'company_description')->delete();
    }
};
