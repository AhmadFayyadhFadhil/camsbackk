<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$buildings = App\Models\Building::with('shifts')->get();
foreach ($buildings as $b) {
    echo "Building: {$b->nama_gedung} (ID: {$b->id})\n";
    echo "  Shifts count: " . $b->shifts->count() . "\n";
    foreach ($b->shifts as $s) {
        echo "    - {$s->nama_shift} ({$s->jam_mulai} - {$s->jam_selesai})\n";
    }
    echo "\n";
}
