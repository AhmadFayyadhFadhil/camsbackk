<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Finding;

echo "=== CLEANING UP UNSELECTED CATEGORIES ON RECENT FINDINGS ===\n";

$updated = Finding::where('deskripsi', 'like', '%BOCOR%')->update(['finding_category_id' => null]);
echo "Updated {$updated} findings.\n";
