<?php

function buildTree($dir, $prefix = '') {
    $output = '';
    if (!is_dir($dir)) {
        return '';
    }

    $files = array_diff(scandir($dir), ['.', '..']);

    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;

        if (is_dir($path)) {
            $output .= $prefix . "├── [DIR] " . $file . PHP_EOL;
            $output .= buildTree($path, $prefix . "│   ");
        } else {
            $output .= $prefix . "├── " . $file . PHP_EOL;
        }
    }

    return $output;
}

$currentDir = getcwd();
echo "جاري فحص المجلدات المستهدفة فقط (app, database, routes)..." . PHP_EOL;

// المجلدات المحددة المطلوبة فقط للتشخيص المعماري
$targetFolders = ['app', 'database', 'routes'];
$treeStructure = '';

foreach ($targetFolders as $folder) {
    $folderPath = $currentDir . DIRECTORY_SEPARATOR . $folder;
    if (is_dir($folderPath)) {
        $treeStructure .= "============ [ " . strtoupper($folder) . " FOLDER ] ============" . PHP_EOL;
        $treeStructure .= buildTree($folderPath);
        $treeStructure .= PHP_EOL;
    }
}

// حفظ النتيجة في ملف نصي نظيف وموجز
file_put_contents('project_tree_filtered.txt', $treeStructure);

echo "اكتمل الأمر! الملف الجديد الجاهز للنسخ هو: project_tree_filtered.txt" . PHP_EOL;
