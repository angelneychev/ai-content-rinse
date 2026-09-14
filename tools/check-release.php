<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
$header = file_get_contents($root . '/ai-content-rinse.php');
$readme = file_get_contents($root . '/readme.txt');
$js = file_get_contents($root . '/assets/admin.js') . file_get_contents($root . '/assets/common.js') . file_get_contents($root . '/assets/editor.js');
preg_match('/^\s*\* Plugin Name:\s*(.+)$/m', $header, $nameMatch);
if (str_contains($readme . ($nameMatch[1] ?? ''), ' - ')) {
    throw new RuntimeException('WordPress.org converts spaced hyphens to typographic dashes. Use a colon or a sentence in directory copy.');
}
// Check authored copy, while allowing deliberate Unicode detection rules and examples.
preg_match_all('/\'(?:\\\\.|[^\'\\\\])*\'/s', $js, $copyMatches);
$copy = $header . $readme . file_get_contents($root . '/README.md') . implode("\n", $copyMatches[0]);
if (preg_match('/[\x{00A0}\x{00AD}\x{00B7}\x{2000}-\x{200F}\x{2010}-\x{201F}\x{2026}\x{2028}-\x{202F}\x{205F}\x{2060}-\x{206F}\x{3000}\x{FEFF}]/u', $copy)) {
    throw new RuntimeException('Authored copy must use ordinary keyboard punctuation and spaces. See WRITING.md.');
}
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
