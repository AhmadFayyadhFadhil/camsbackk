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
        // Insert new configuration keys if they do not exist to prevent primary key collision
        $existingKeys = DB::table('system_settings')->whereIn('key', [
            'company_name',
            'company_logo',
            'app_footer_text'
        ])->pluck('key')->toArray();

        $settings = [];

        if (!in_array('company_name', $existingKeys)) {
            $settings[] = [
                'id' => (string) Str::uuid(),
                'key' => 'company_name',
                'value' => 'CAMS PANDAAN',
                'type' => 'string',
                'description' => 'Nama perusahaan atau aplikasi yang ditampilkan di header dan sidebar',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!in_array('company_logo', $existingKeys)) {
            $settings[] = [
                'id' => (string) Str::uuid(),
                'key' => 'company_logo',
                'value' => '',
                'type' => 'string',
                'description' => 'Path file gambar logo perusahaan (kosongkan untuk menggunakan logo default)',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!in_array('app_footer_text', $existingKeys)) {
            $settings[] = [
                'id' => (string) Str::uuid(),
                'key' => 'app_footer_text',
                'value' => '© 2026 CAMS Pandaan. All rights reserved.',
                'type' => 'string',
                'description' => 'Teks hak cipta / footer yang ditampilkan di bagian bawah sidebar',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (count($settings) > 0) {
            DB::table('system_settings')->insert($settings);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', [
            'company_name',
            'company_logo',
            'app_footer_text'
        ])->delete();
    }
};
