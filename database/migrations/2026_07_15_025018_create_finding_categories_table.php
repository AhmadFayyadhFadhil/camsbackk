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
        Schema::create('finding_categories', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->string('nama_kategori');
            $table->string('kode_kategori', 30)->unique();
            $table->timestamps();
        });

        // Seed data default
        DB::table('finding_categories')->insert([
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'nama_kategori' => 'Kelistrikan',
                'kode_kategori' => 'ELEC',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'nama_kategori' => 'AC & HVAC',
                'kode_kategori' => 'HVAC',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'nama_kategori' => 'Plumbing / Kran Air',
                'kode_kategori' => 'PLUMB',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'nama_kategori' => 'Sipil & Konstruksi',
                'kode_kategori' => 'CIVIL',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'nama_kategori' => 'Lainnya',
                'kode_kategori' => 'OTHER',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finding_categories');
    }
};
