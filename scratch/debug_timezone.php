<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ChecklistSubmission;
use App\Http\Resources\ChecklistSubmissionResource;

echo "=== CHECKING SUBMISSION TIMEZONE OUTPUT ===\n";
echo "Config timezone: " . config('app.timezone') . "\n";
echo "Date default timezone: " . date_default_timezone_get() . "\n";
echo "Now UTC: " . \Carbon\Carbon::now('UTC')->toDateTimeString() . "\n";
echo "Now Asia/Jakarta: " . \Carbon\Carbon::now('Asia/Jakarta')->toDateTimeString() . "\n\n";

$subs = ChecklistSubmission::where('status', \App\Enums\SubmissionStatusEnum::SUBMITTED)->latest()->take(5)->get();

foreach ($subs as $sub) {
    echo "ID: {$sub->id}\n";
    echo "Raw submitted_at in DB: {$sub->getRawOriginal('submitted_at')}\n";
    echo "Carbon submitted_at: " . ($sub->submitted_at ? $sub->submitted_at->toDateTimeString() : 'null') . "\n";
    echo "toIso8601String: " . ($sub->submitted_at ? $sub->submitted_at->toIso8601String() : 'null') . "\n";
    
    $resource = new ChecklistSubmissionResource($sub);
    $json = $resource->toArray(request());
    echo "Resource submitted_at: " . ($json['submitted_at'] ?? 'null') . "\n";
    echo "Resource submission_time: " . ($json['submission_time'] ?? 'null') . "\n";
    echo "----------------------------------------\n";
}
