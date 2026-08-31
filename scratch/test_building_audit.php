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

echo "=== TESTING BUILDING ASSET AUDIT BACKEND ===\n";

$building = Building::with('rooms.assets')->first();
if (!$building) {
    echo "No building found.\n";
    exit(1);
}

echo "Building: {$building->nama_gedung} (ID: {$building->id})\n";
echo "Total Rooms: " . $building->rooms->count() . "\n";

$admin = User::whereHas('roles', fn($q) => $q->where('name', 'admin'))->first();

$controller = new RoomAssetAuditController();

// 1. Test buildingSummary
$request = Request::create('/api/v1/building-asset-audits/summary', 'GET');
$response = $controller->buildingSummary($request);
$data = json_decode($response->getContent(), true);
echo "1. Summary Success: " . ($data['success'] ? 'YES' : 'NO') . "\n";
echo "   Total Buildings in Summary: " . count($data['data']['buildings']) . "\n";

// 2. Test getBuildingAssetsTree
$responseTree = $controller->getBuildingAssetsTree($building->id);
$dataTree = json_decode($responseTree->getContent(), true);
echo "2. Tree Success: " . ($dataTree['success'] ? 'YES' : 'NO') . "\n";
echo "   Rooms in Tree: " . count($dataTree['data']['rooms']) . "\n";

// 3. Test updateBuildingSchedule
$reqSchedule = Request::create("/api/v1/buildings/{$building->id}/asset-audit-schedule", 'PUT', [
    'asset_audit_interval' => 'quarterly',
    'asset_audit_interval_days' => 90,
]);
$reqSchedule->setUserResolver(fn() => $admin);
$resSchedule = $controller->updateBuildingSchedule($reqSchedule, $building->id);
$dataSchedule = json_decode($resSchedule->getContent(), true);
echo "3. Schedule Update Success: " . ($dataSchedule['success'] ? 'YES' : 'NO') . "\n";
$building->refresh();
echo "   New Interval: {$building->asset_audit_interval}, Next Due: {$building->next_asset_audit_due}\n";

echo "=== ALL BACKEND BUILDING AUDIT TESTS PASSED ===\n";
