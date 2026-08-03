<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TaskGeneratorService;
use Carbon\Carbon;

class GenerateDailyTasks extends Command
{
    protected $signature = 'cams:generate-tasks {--date= : Tanggal pembuatan tugas (YYYY-MM-DD)}';
    protected $description = 'Membuat tugas harian berdasarkan jadwal aktif dan penugasan CS';

    protected TaskGeneratorService $generatorService;

    public function __construct(TaskGeneratorService $generatorService)
    {
        parent::__construct();
        $this->generatorService = $generatorService;
    }

    public function handle()
    {
        $dateOption = $this->option('date');
        $targetDate = $dateOption ? Carbon::parse($dateOption, 'Asia/Jakarta') : Carbon::today('Asia/Jakarta');

        $this->info("Menjalankan pembuatan tugas untuk tanggal: " . $targetDate->toDateString());

        $result = $this->generatorService->generateForDate($targetDate);

        $this->info("Berhasil: " . $result['generated'] . " dibuat, " . $result['skipped'] . " dilewati.");
        
        return self::SUCCESS;
    }
}
