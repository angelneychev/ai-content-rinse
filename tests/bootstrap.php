<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code(403); exit; }
$root = getenv('AICR_WP_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) { throw new RuntimeException('Set AICR_WP_ROOT to a disposable WordPress installation.'); }
require $root . '/wp-load.php';
if (!function_exists('aicr_clean_text')) { throw new RuntimeException('Activate AI Content Rinse in the test installation.'); }
