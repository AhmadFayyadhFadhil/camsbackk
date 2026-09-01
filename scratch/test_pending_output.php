<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Http\Controllers\Api\V1\VerificationController;
use Illuminate\Http\Request;

$supervisor = User::whereHas('roles', fn($q) => $q->where('name', 'supervisor'))->first();
$controller = new VerificationController();
$req = Request::create('/api/v1/verifications/pending', 'GET');
$req->setUserResolver(fn() => $supervisor);
auth()->login($supervisor);

$response = $controller->pending($req);
$json = json_decode($response->getContent(), true);

echo "Pending Count: " . count($json['data'] ?? []) . "\n";
foreach ($json['data'] ?? [] as $item) {
    echo "Room: {$item['task']['room']['name']} | Submission Time: {$item['submission_time']} | Submitted At: {$item['submitted_at']}\n";
}
