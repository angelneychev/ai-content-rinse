<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
$header = file_get_contents($root . '/ai-content-rinse.php');
$readme = file_get_contents($root . '/readme.txt');
preg_match('/^\s*\* Version:\s*(\d+\.\d+\.\d+)\s*$/m', $header, $versionMatch);
preg_match('/^Stable tag:\s*(\d+\.\d+\.\d+)\s*$/m', $readme, $stableMatch);
$version = $versionMatch[1] ?? '';
if (!$version || $version !== ($stableMatch[1] ?? '') || !str_contains($readme, '= ' . $version . ' =')) {
    throw new RuntimeException('Plugin version, stable tag and changelog must match.');
}
$tag = getenv('GITHUB_REF_TYPE') === 'tag' ? getenv('GITHUB_REF_NAME') : '';
if ($tag && $tag !== 'v' . $version) throw new RuntimeException('Release tag does not match the plugin version.');
echo "Validated version $version\n";
return $version;
