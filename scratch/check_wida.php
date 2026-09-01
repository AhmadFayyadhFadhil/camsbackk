<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Room;
use App\Models\DailyTask;

$widaRooms = Room::where('nama_ruangan', 'like', '%WIDA%')
    ->orWhere('kode_ruangan', 'like', '%WDA%')
    ->get(['id', 'nama_ruangan', 'kode_ruangan', 'qr_code_token', 'building_id']);

echo "=== WIDA ROOMS IN DATABASE ===\n";
foreach ($widaRooms as $r) {
    echo "ID: {$r->id} | Name: {$r->nama_ruangan} | Code: {$r->kode_ruangan} | Token: {$r->qr_code_token} | Building: {$r->building_id}\n";
}

$todayTasks = DailyTask::whereHas('room', fn($q) => $q->where('nama_ruangan', 'like', '%WIDA%'))
    ->whereDate('tanggal_tugas', today())
    ->with('room')
    ->get();

echo "\n=== TODAY TASKS FOR WIDA ROOMS ===\n";
foreach ($todayTasks as $t) {
    echo "Task ID: {$t->id} | Room ID: {$t->room_id} | Room Name: {$t->room?->nama_ruangan} | Room Code: {$t->room?->kode_ruangan} | Room Token in Room Obj: {$t->room?->qr_code_token}\n";
}
