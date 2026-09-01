<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Room;
use App\Models\ChecklistSubmission;
use App\Http\Resources\ChecklistSubmissionResource;

echo "=== INSPECTING FA DEPT. HEAD ROOM & SUBMISSION ===\n";

$rooms = Room::where('nama_ruangan', 'LIKE', '%FA%')->orWhere('kode_ruangan', 'LIKE', '%RKFA%')->get();
echo "Found " . count($rooms) . " matching rooms in DB:\n";
foreach ($rooms as $r) {
    echo "Room ID: {$r->id}\n";
    echo "Name: {$r->nama_ruangan}\n";
    echo "Code: {$r->kode_ruangan}\n";
    echo "QR Token: {$r->qr_code_token}\n";
    echo "Building ID: {$r->building_id}\n";
    echo "Is Active: " . ($r->is_active ? 'YES' : 'NO') . "\n";
    echo "----------------------------------------\n";
}

$submissions = ChecklistSubmission::whereHas('task.room', function($q) {
    $q->where('nama_ruangan', 'LIKE', '%FA%');
})->with(['task.room'])->latest()->take(3)->get();

echo "\nSubmissions for FA:\n";
foreach ($submissions as $s) {
    echo "Submission ID: {$s->id}\n";
    echo "Task ID: {$s->task_id}\n";
    echo "Task Room ID: {$s->task?->room_id}\n";
    echo "Room Name: {$s->task?->room?->nama_ruangan}\n";
    echo "Room Code: {$s->task?->room?->kode_ruangan}\n";
    echo "Room QR Token: {$s->task?->room?->qr_code_token}\n";
    
    $resource = new ChecklistSubmissionResource($s);
    $resData = $resource->toArray(request());
    echo "Resource Task Room: " . json_encode($resData['task']['room'] ?? []) . "\n";
    echo "----------------------------------------\n";
}
