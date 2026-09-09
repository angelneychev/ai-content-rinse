<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/bootstrap.php';
wp_set_current_user(get_user_by('login', 'administrator')->ID);
$count = 0;
function check($condition, $message) { global $count; if (!$condition) { throw new RuntimeException($message); } ++$count; }
function request($params) { $r = new WP_REST_Request('POST'); foreach ($params as $k => $v) $r->set_param($k, $v); return $r; }
$protected = '<!-- wp:paragraph {"label":"a' . "\u{200B}" . 'b"} --><p title="a' . "\u{200B}" . 'b">Български 👩‍💻&nbsp;';
$source = $protected . "a\u{200B}b\u{FEFF}c\u{00AD}d</p><!-- /wp:paragraph --><code>a\u{200B}b</code>";
$clean = aicr_clean_text($source);
check($clean['text'] === $protected . "abcd</p><!-- /wp:paragraph --><code>a\u{200B}b</code>", 'Preserve markup, Cyrillic, emoji and code');
check(array_sum($clean['counts']) === 3, 'Exact count');
check(aicr_clean_text($clean['text'])['text'] === $clean['text'], 'Idempotency');
$id = wp_insert_post(wp_slash(array('post_title' => "Test\u{200B} title", 'post_content' => $source, 'post_excerpt' => 'Literal \\ path', 'post_status' => 'draft')));
try {
    $original = aicr_fields(get_post($id));
    $p = aicr_preview(request(['id'=>$id]));
    check($p['changed'], 'Preview changed');
    check(!is_wp_error(aicr_apply(request(['token'=>$p['token']]))), 'Apply');
    check(aicr_fields(get_post($id)) === $p['after'], 'Exact saved data');
    check(!is_wp_error(aicr_restore(request(['id'=>$id]))), 'Restore');
    check(aicr_fields(get_post($id)) === $original, 'Exact restored bytes including slashes');
    $p = aicr_preview(request(['id'=>$id]));
    wp_update_post(['ID'=>$id, 'post_title'=>'Concurrent edit']);
    check(is_wp_error(aicr_apply(request(['token'=>$p['token']]))), 'Stale preview rejected');
    wp_update_post(wp_slash(array_merge(['ID'=>$id], $original)));
    $p = aicr_preview(request(['id'=>$id])); aicr_apply(request(['token'=>$p['token']]));
    wp_update_post(['ID'=>$id, 'post_title'=>'Later edit']);
    check(is_wp_error(aicr_restore(request(['id'=>$id]))), 'Restore conflict rejected');
    wp_set_current_user(0);
    check(!aicr_permission(), 'Anonymous denied');
    check(is_wp_error(aicr_post($id)), 'Anonymous cannot edit');
} finally { wp_set_current_user(get_user_by('login','administrator')->ID); wp_delete_post($id, true); }
// Real decodable image fixtures, injected textual metadata, byte-identical restoration.
$im = imagecreatetruecolor(12, 12);
foreach (['png'=>'image/png', 'jpeg'=>'image/jpeg', 'webp'=>'image/webp'] as $format=>$mime) {
    ob_start(); ('image'.$format)($im); $bytes = ob_get_clean();
    $bytes = aicr_strip_metadata($bytes, $mime)['data'];
    if ($format === 'png') {
        $body = "Comment\0test"; $chunk = pack('N',strlen($body)).'tEXt'.$body.pack('N',crc32('tEXt'.$body));
        $input = substr($bytes,0,-12).$chunk.substr($bytes,-12);
    } elseif ($format === 'jpeg') {
        $input = substr($bytes,0,2)."\xff\xfe".pack('n',6).'test'.substr($bytes,2);
    } else {
        $body = 'WEBP'.substr($bytes,12).'XMP '.pack('V',4).'test'; $input = 'RIFF'.pack('V',strlen($body)).$body;
    }
    $out = aicr_strip_metadata($input,$mime);
    check($out['removed']===1, "$format metadata count");
    check($out['data']===$bytes, "$format source bytes preserved");
    check(imagecreatefromstring($out['data'])!==false, "$format decodable");
    try { aicr_strip_metadata(substr($input,0,15),$mime); check(false,'Malformed accepted'); } catch (RuntimeException $e) { check(true,'Malformed rejected'); }
}
imagedestroy($im);
echo "Passed $count assertions.\n";
