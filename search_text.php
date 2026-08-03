<?php
// PHP Script to search for "Toilet" in PHP and JS files recursively
function searchDir($dir, $pattern) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $file) {
        if ($file->isDir()) continue;
        $filePath = $file->getPathname();
        if (strpos($filePath, 'node_modules') !== false || strpos($filePath, 'vendor') !== false || strpos($filePath, '.git') !== false) {
            continue;
        }
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        if (!in_array($ext, ['php', 'js', 'jsx', 'json', 'sql'])) continue;
        
        $content = file_get_contents($filePath);
        if (strpos($content, $pattern) !== false) {
            echo "Found in: $filePath\n";
        }
    }
}

echo "Searching cams-backend for 'Toilet':\n";
searchDir('d:/cams-backend', 'Toilet');
echo "\nSearching cams-fe for 'Toilet':\n";
searchDir('d:/cams-fe', 'Toilet');
