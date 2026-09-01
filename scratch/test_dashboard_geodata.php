<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Http\Controllers\Api\V1\DashboardController;
use Illuminate\Http\Request;

echo "=== TESTING DASHBOARD GEODATA & INSPECTION TRAIL ===\n";

$supervisor = User::whereHas('roles', fn($q) => $q->where('name', 'supervisor'))->first();
$controller = new DashboardController();
$req = Request::create('/api/v1/dashboard/supervisor?refresh=1', 'GET');
$req->setUserResolver(fn() => $supervisor);
auth()->login($supervisor);

$response = $controller->supervisor($req);
$json = json_decode($response->getContent(), true);

echo "Breakdown Buildings:\n";
foreach ($json['data']['breakdown_per_building'] ?? [] as $b) {
    echo "- {$b['building_name']} ({$b['building_code']}): Lat=" . ($b['latitude'] ?? 'null') . ", Lng=" . ($b['longitude'] ?? 'null') . ", Radius=" . ($b['radius_meter'] ?? 'null') . "m\n";
}

echo "\nInspection Trail (Total: " . count($json['data']['inspection_trail'] ?? []) . "):\n";
foreach ($json['data']['inspection_trail'] ?? [] as $t) {
    echo "- Room: {$t['room_name']} ({$t['room_code']}) | Time: {$t['time']} | CS: {$t['cs_name']} | Status: {$t['status']} | Bldg: {$t['building_name']}\n";
}
