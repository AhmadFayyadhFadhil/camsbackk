<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ChecklistSubmission;
use Illuminate\Support\Facades\DB;

// Fix older today rows that were saved with UTC offset (diff of 7 hours)
$rows = DB::table('checklist_submissions')
    ->whereDate('submitted_at', today()->toDateString())
    ->whereTime('submitted_at', '<', '05:00:00')
    ->get();

echo "Found " . count($rows) . " rows created in UTC today.\n";

foreach ($rows as $row) {
    $oldTime = $row->submitted_at;
    $newTime = \Carbon\Carbon::parse($oldTime)->addHours(7)->toDateTimeString();
    echo "Updating ID {$row->id}: {$oldTime} -> {$newTime}\n";
    DB::table('checklist_submissions')->where('id', $row->id)->update([
        'submitted_at' => $newTime,
        'created_at' => \Carbon\Carbon::parse($row->created_at)->addHours(7)->toDateTimeString(),
        'updated_at' => \Carbon\Carbon::parse($row->updated_at)->addHours(7)->toDateTimeString(),
    ]);
}

echo "Done updating!\n";
