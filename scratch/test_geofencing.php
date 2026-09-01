<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Building;
use App\Models\Room;
use App\Models\User;
use App\Helpers\GeoHelper;
use App\Http\Controllers\Api\V1\SubmissionController;
use Illuminate\Http\Request;

echo "=== TESTING BUILDING GEOFENCING ===\n";

// 1. Test GeoHelper Haversine
// PT Widarta Bhakti Pandaan (approx -7.643212, 112.698765)
$factoryLat = -7.643212;
$factoryLng = 112.698765;

// Close position (~50 meters away)
$nearLat = -7.643500;
$nearLng = 112.698900;
$nearDistance = GeoHelper::haversineDistance($factoryLat, $factoryLng, $nearLat, $nearLng);
echo "Near distance: " . round($nearDistance, 2) . " meters\n";

// Far position (~5 kilometers away)
$farLat = -7.680000;
$farLng = 112.720000;
$farDistance = GeoHelper::haversineDistance($factoryLat, $factoryLng, $farLat, $farLng);
echo "Far distance: " . round($farDistance, 2) . " meters\n";

// 2. Set Building Coordinates
$building = Building::first();
if ($building) {
    $building->update([
        'latitude' => $factoryLat,
        'longitude' => $factoryLng,
        'radius_meter' => 250,
    ]);
    echo "Updated Building: {$building->nama_gedung} | Lat: {$building->latitude} | Lng: {$building->longitude} | Radius: {$building->radius_meter}m\n";
}

// 3. Test Scan with Far GPS (Should fail 422)
$cs = User::whereHas('roles', fn($q) => $q->where('name', 'cs'))->first();
$room = Room::where('building_id', $building->id)->first();

if ($cs && $room) {
    $controller = new SubmissionController();
    
    // Far test
    $reqFar = Request::create('/api/v1/submissions/scan', 'POST', [
        'room_id' => $room->id,
        'qr_code_token' => $room->qr_code_token,
        'latitude' => $farLat,
        'longitude' => $farLng,
    ]);
    $reqFar->setUserResolver(fn() => $cs);
    
    $resFar = $controller->scanQrCode($reqFar);
    echo "Far GPS Status Code: " . $resFar->getStatusCode() . "\n";
    $jsonFar = json_decode($resFar->getContent(), true);
    echo "Far GPS Response Message: " . ($jsonFar['message'] ?? 'none') . "\n";

    // Near test
    $reqNear = Request::create('/api/v1/submissions/scan', 'POST', [
        'room_id' => $room->id,
        'qr_code_token' => $room->qr_code_token,
        'latitude' => $nearLat,
        'longitude' => $nearLng,
    ]);
    $reqNear->setUserResolver(fn() => $cs);
    
    $resNear = $controller->scanQrCode($reqNear);
    echo "Near GPS Status Code: " . $resNear->getStatusCode() . "\n";
    $jsonNear = json_decode($resNear->getContent(), true);
    echo "Near GPS Response Success: " . ($jsonNear['success'] ? 'YES' : 'NO') . "\n";
}

echo "=== GEOFENCING TEST COMPLETED ===\n";
