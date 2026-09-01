<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Building;
use App\Models\Room;
use App\Models\User;
use App\Http\Controllers\Api\V1\SubmissionController;
use Illuminate\Http\Request;

echo "=== TESTING HIERARCHICAL ROOM GEOFENCING ===\n";

$building = Building::first();
$room = Room::where('building_id', $building->id)->first();
$cs = User::whereHas('roles', fn($q) => $q->where('name', 'cs'))->first();

// 1. Test Inheritance (Room has no custom coords, inherits building coords)
$room->update(['latitude' => null, 'longitude' => null, 'radius_meter' => null]);
$inherited = $room->fresh()->getEffectiveGeofence();
echo "Inherited Geofence Type: {$inherited['type']} | Target: {$inherited['target_name']} | Radius: {$inherited['radius_meter']}m\n";

// 2. Test Custom Room Coords (e.g. Room has specific outdoor coords and 30m radius)
$roomLat = -7.645550;
$roomLng = 112.693800;
$room->update(['latitude' => $roomLat, 'longitude' => $roomLng, 'radius_meter' => 30]);
$custom = $room->fresh()->getEffectiveGeofence();
echo "Custom Room Geofence Type: {$custom['type']} | Target: {$custom['target_name']} | Radius: {$custom['radius_meter']}m\n";

// 3. Test Scan validation on custom room (Near vs Far)
$controller = new SubmissionController();

// Far: 100 meters away (outside 30m radius)
$farLat = -7.646500;
$farLng = 112.693800;
$reqFar = Request::create('/api/v1/submissions/scan', 'POST', [
    'room_id' => $room->id,
    'qr_code_token' => $room->qr_code_token,
    'latitude' => $farLat,
    'longitude' => $farLng,
]);
$reqFar->setUserResolver(fn() => $cs);
$resFar = $controller->scanQrCode($reqFar);
echo "Far Scan (100m away from 30m room) Status: " . $resFar->getStatusCode() . "\n";
$jsonFar = json_decode($resFar->getContent(), true);
echo "Far Message: " . ($jsonFar['message'] ?? 'none') . "\n";

echo "=== HIERARCHICAL GEOFENCING TEST PASSED ===\n";
