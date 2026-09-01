<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ChecklistSubmission;
use App\Models\Task;
use App\Models\Room;
use App\Models\User;
use App\Models\Verification;
use App\Http\Controllers\Api\V1\VerificationController;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

echo "=== TESTING ON-SITE VERIFICATION BACKEND ===\n";

$admin = User::whereHas('roles', fn($q) => $q->where('name', 'admin'))->first();
$supervisor = User::whereHas('roles', fn($q) => $q->where('name', 'supervisor'))->first() ?: $admin;
$cs = User::whereHas('roles', fn($q) => $q->where('name', 'cs'))->first() ?: $admin;

$schedule = \App\Models\Schedule::with('room')->first();
$room = $schedule->room;

// 1. Create a dummy task & submission in SUBMITTED status
$task = Task::create([
    'room_id' => $room->id,
    'cs_user_id' => $cs->id,
    'schedule_id' => $schedule->id,
    'shift_id' => $schedule->shift_id,
    'tanggal_task' => date('Y-m-d', strtotime('+ ' . rand(100, 9999) . ' days')),
    'status' => \App\Enums\TaskStatusEnum::WAITING_VERIFICATION,
]);

$submission = ChecklistSubmission::create([
    'task_id' => $task->id,
    'cs_user_id' => $cs->id,
    'submitted_at' => now(),
    'scan_token_used' => 'TEST-TOKEN-' . uniqid(),
    'catatan_cs' => 'Ruangan sudah dibersihkan total.',
    'status' => \App\Enums\SubmissionStatusEnum::SUBMITTED,
]);

echo "Created Dummy Submission: {$submission->id} for Room: {$room->nama_ruangan} (Kode: {$room->kode_ruangan})\n";

$controller = new VerificationController();
auth()->login($supervisor);

// 2. Test Approval with WRONG QR Code (Should return 422 error)
$reqWrong = Request::create("/api/v1/verifications/{$submission->id}/approve", 'POST', [
    'notes' => 'Cek fisik.',
    'room_qr_code' => 'WRONG-QR-CODE-123',
]);
$reqWrong->setUserResolver(fn() => $supervisor);
$resWrong = $controller->approve($reqWrong, $submission->id);
$dataWrong = json_decode($resWrong->getContent(), true);
echo "2. Wrong QR Test: " . (!$dataWrong['success'] ? "SUCCESSFULLY REJECTED (Expected: {$dataWrong['message']})" : "FAILED (Should be rejected)") . "\n";

// 3. Test Approval with CORRECT QR Code & Foto Inspeksi
Storage::fake('public');
$fakePhoto = UploadedFile::fake()->image('inspeksi_supervisor.jpg', 600, 600);

$reqCorrect = Request::create("/api/v1/verifications/{$submission->id}/approve", 'POST', [
    'notes' => 'Sudah dicek langsung di ruangan oleh supervisor. Lantai bersih dan wangi.',
    'room_qr_code' => $room->kode_ruangan,
    'latitude' => -7.536123,
    'longitude' => 112.789123,
    'qr_scanned_at' => now()->toIso8601String(),
    'is_onsite_verified' => 1,
], [], ['foto_inspeksi' => $fakePhoto]);
$reqCorrect->setUserResolver(fn() => $supervisor);
$resCorrect = $controller->approve($reqCorrect, $submission->id);
$dataCorrect = json_decode($resCorrect->getContent(), true);

echo "3. Correct On-Site Approval Test: " . ($dataCorrect['success'] ? "SUCCESS (Message: {$dataCorrect['message']})" : "FAILED") . "\n";

$verification = Verification::where('submission_id', $submission->id)->first();
if ($verification) {
    echo "   Verification ID: {$verification->id}\n";
    echo "   Is Onsite Verified: " . ($verification->is_onsite_verified ? 'YES' : 'NO') . "\n";
    echo "   Foto Inspeksi Path: {$verification->foto_inspeksi_path}\n";
    echo "   QR Scanned At: {$verification->qr_scanned_at}\n";
}

echo "=== ALL ON-SITE VERIFICATION BACKEND TESTS PASSED ===\n";
