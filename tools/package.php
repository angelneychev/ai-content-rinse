<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$version = require __DIR__ . '/check-release.php';
$root = dirname(__DIR__);
$files = ['ai-content-rinse.php','includes.php','media.php','readme.txt','LICENSE.txt','THIRD-PARTY-NOTICES.txt','assets/admin.js','assets/common.js','assets/editor.js','assets/admin.css'];
$build = $root . '/.build';
if (!is_dir($build) && !mkdir($build,0755,true)) throw new RuntimeException('Cannot create build directory.');
$zipPath = $build . '/ai-content-rinse-' . $version . '.zip';
$zip = new ZipArchive();
if (true !== $zip->open($zipPath,ZipArchive::CREATE | ZipArchive::OVERWRITE)) throw new RuntimeException('Cannot open release ZIP.');
foreach ($files as $file) {
    $source = $root . '/' . $file;
    if (!is_file($source)) throw new RuntimeException('Missing runtime file: ' . $file);
    $target = $build . '/ai-content-rinse/' . $file;
    if (!is_dir(dirname($target))) mkdir(dirname($target),0755,true);
    if (!copy($source,$target) || !$zip->addFile($source,'ai-content-rinse/'.$file)) throw new RuntimeException('Cannot package: ' . $file);
}
if (!$zip->close()) throw new RuntimeException('Cannot finalize release ZIP.');
$check = new ZipArchive(); $check->open($zipPath);
if ($check->numFiles !== count($files)) throw new RuntimeException('Unexpected ZIP file count.');
foreach ($files as $file) {
    if ($check->getFromName('ai-content-rinse/'.$file) !== file_get_contents($root.'/'.$file)) throw new RuntimeException('ZIP content mismatch: '.$file);
}
$check->close();
echo $zipPath . "\nSHA256 " . hash_file('sha256',$zipPath) . "\n";
