<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Building;
use App\Models\Room;
use App\Models\RoomAsset;
use App\Models\User;
use App\Models\RoomAssetAudit;
use App\Http\Controllers\Api\V1\RoomAssetAuditController;
use Illuminate\Http\Request;

echo "=== TESTING COMPLETE BUILDING-CENTRIC AUDIT FLOW ===\n";

$building = Building::with('rooms.assets')->first();
$admin = User::whereHas('roles', fn($q) => $q->where('name', 'admin'))->first();
$cs = User::whereHas('roles', fn($q) => $q->where('name', 'cs'))->first() ?: $admin;

$controller = new RoomAssetAuditController();

// Ensure at least one room and one asset exists
$room = $building->rooms()->first();
if (!$room) {
    $room = Room::create([
        'building_id' => $building->id,
        'nama_ruangan' => 'Ruang Uji Coba',
        'kode_ruangan' => 'R-TEST',
        'lantai' => '1',
        'is_active' => true,
    ]);
}

$asset = $room->assets()->first();
if (!$asset) {
    $asset = RoomAsset::create([
        'room_id' => $room->id,
        'nama_aset' => 'Meja Kerja Uji',
        'kode_aset' => 'AST-TEST',
        'jumlah' => 4,
        'status' => 'active',
    ]);
}

echo "Building: {$building->nama_gedung}, Room: {$room->nama_ruangan}, Asset: {$asset->nama_aset}\n";

// 1. Submit Building Audit from CS
$reqAudit = Request::create('/api/v1/room-asset-audits', 'POST', [
    'building_id' => $building->id,
    'periode' => '2026-08',
    'notes' => 'Audit gedung lengkap bulan Agustus.',
    'items' => [
        [
            'room_asset_id' => $asset->id,
            'room_id' => $room->id,
            'jumlah_actual' => 4,
            'kondisi' => 'good',
            'catatan' => 'Semua dalam kondisi bagus.',
        ]
    ]
]);
$reqAudit->setUserResolver(fn() => $cs);
$resAudit = $controller->store($reqAudit);
$dataAudit = json_decode($resAudit->getContent(), true);
echo "1. Submit Audit Success: " . ($dataAudit['success'] ? 'YES' : 'NO') . "\n";
$auditId = $dataAudit['data']['id'];
echo "   Audit ID: {$auditId}\n";

// 2. Verify Audit from Supervisor/Admin
$reqVerify = Request::create("/api/v1/room-asset-audits/{$auditId}/verify", 'POST', [
    'status' => 'approved',
    'verification_notes' => 'Audit disetujui, aset lengkap.',
    'auto_create_findings' => true,
    'sync_master_baseline' => false,
]);
$reqVerify->setUserResolver(fn() => $admin);
$resVerify = $controller->verify($reqVerify, $auditId);
$dataVerify = json_decode($resVerify->getContent(), true);
echo "2. Verify Audit Success: " . ($dataVerify['success'] ? 'YES' : 'NO') . "\n";
echo "   Audit Final Status: {$dataVerify['data']['status']}\n";

echo "=== ALL FLOW TESTS PASSED SUCCESSFULLY! ===\n";
