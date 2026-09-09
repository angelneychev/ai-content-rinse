<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/bootstrap.php';
$count = 0;
function verify030($condition, $label) {
    global $count;
    if (!$condition) throw new RuntimeException($label);
    $count++;
}
function req030($params) {
    $r = new WP_REST_Request('POST');
    foreach ($params as $key => $value) $r->set_param($key, $value);
    return $r;
}
function pngChunk030($type, $body) {
    return pack('N', strlen($body)) . $type . $body . pack('N', crc32($type . $body));
}
$admin = get_user_by('login', 'administrator')->ID;
wp_set_current_user($admin);
$text = "<!-- wp:paragraph --><p title=\"x > —\">- — – a\u{2063}b\u{E0100}c</p><code>—\u{200B}</code><!-- /wp:paragraph -->";
$r = aicr_clean_text($text);
verify030(implode('', array_column($r['segments'], 'before')) === $text, 'Segments reconstruct complete original');
verify030(implode('', array_column($r['segments'], 'after')) === $r['text'], 'Segments reconstruct exact saved text');
$changes = array_values(array_filter($r['segments'], fn($s) => $s['label'] !== ''));
verify030(count($changes) === 4, 'Only changed text characters highlighted');
verify030(array_column($changes, 'label') === ['U+2014','U+2013','U+2063','U+E0100'], 'Exact Unicode labels including supplementary plane');
verify030(count(array_filter($changes, fn($s) => $s['after'] === '-')) === 2, 'Only two replacements marked green');
verify030(str_contains($r['text'], 'title="x > —"') && str_contains($r['text'], "<code>—\u{200B}</code>"), 'Quoted angle bracket and protected code preserved');
verify030(aicr_paste(req030(['text'=>'<code>—</code>', 'format'=>'plain']))['text'] === '<code>-</code>', 'Plain paste cleans literal markup text');
verify030(aicr_paste(req030(['text'=>'<code>—</code>', 'format'=>'html']))['text'] === '<code>—</code>', 'HTML paste preserves code');
verify030(is_wp_error(aicr_paste(req030(['text'=>"\xff"]))), 'Reject invalid UTF-8');
verify030(is_wp_error(aicr_paste(req030(['text'=>str_repeat('a', MB_IN_BYTES+1)]))), 'Bound paste input');

$im = imagecreatetruecolor(12, 12);
imagesetpixel($im, 2, 3, imagecolorallocate($im, 87, 13, 250));
ob_start(); imagepng($im); $base = ob_get_clean();
$kept = pngChunk030('vpAg', pack('NNc', 12, 12, 0));
$expected = substr($base,0,33).$kept.substr($base,33);
$manifest = pngChunk030('caBX', "jumb\0c2pa\0OpenAI\0GPT");
$injected = substr($expected,0,33).$manifest.pngChunk030('tEXt',"Comment\0fixture").substr($expected,33);
$clean = aicr_strip_metadata($injected,'image/png');
verify030($clean['removed'] === 2 && $clean['data'] === $expected, 'C2PA/text removed, all other PNG bytes preserved');
verify030(aicr_media_provenance_hints($injected,'image/png')['detected'], 'PNG provenance detected');
verify030(!aicr_media_provenance_hints($expected,'image/png')['detected'], 'PNG provenance absent after cleaning');
$corrupt = $injected; $corrupt[45] = chr(ord($corrupt[45]) ^ 1);
try { aicr_strip_metadata($corrupt,'image/png'); verify030(false,'Bad PNG checksum accepted'); } catch (RuntimeException $e) { verify030(true,'Corrupted PNG chunk rejected'); }

$attachment = 0;
$paths = [];
try {
    $prefix = 'aicr-integration-' . wp_generate_uuid4();
    foreach (['main','thumb','original','edit-backup'] as $label) {
        $upload = wp_upload_bits($prefix.'-'.$label.'.png', null, $injected);
        if ($upload['error']) throw new RuntimeException($upload['error']);
        $paths[$label] = $upload['file'];
    }
    $attachment = wp_insert_attachment(['post_title'=>$prefix, 'post_mime_type'=>'image/png','post_status'=>'inherit'], $paths['main']);
    $metadata = [
        'width'=>12,'height'=>12,'file'=>_wp_relative_upload_path($paths['main']), 'filesize'=>strlen($injected),
        'original_image'=>basename($paths['original']),
        'sizes'=>['thumbnail'=>['file'=>basename($paths['thumb']), 'width'=>12, 'height'=>12, 'mime-type'=>'image/png', 'filesize'=>strlen($injected)]],
        'image_meta'=>['test_marker'=>'retained']
    ];
    wp_update_attachment_metadata($attachment,$metadata);
    update_post_meta($attachment,'_wp_attachment_backup_sizes',['full-orig'=>['file'=>basename($paths['edit-backup']),'width'=>12,'height'=>12]]);
    $url = wp_get_attachment_url($attachment);
    $attachmentCount = (int)wp_count_posts('attachment')->inherit;
    $preview = aicr_media_clean(req030(['id'=>$attachment]));
    verify030(!is_wp_error($preview) && $preview['removed'] === 8 && count($preview['files']) === 4, 'All four existing variants scanned');
    verify030(!isset($preview['hashes']) && !str_contains(wp_json_encode($preview), 'C:\\'), 'No filesystem paths exposed');
    verify030(is_wp_error(aicr_media_clean(req030(['id'=>$attachment,'save'=>true]))), 'Save without reviewed token rejected');
    verify030(file_get_contents($paths['main']) === $injected, 'Rejected save preserves source');
    wp_set_current_user(0);
    verify030(is_wp_error(aicr_media_clean(req030(['id'=>$attachment]))), 'Anonymous media denied');
    wp_set_current_user($admin);
    // Change only a derivative after preview: entire operation must stop before the main file is touched.
    file_put_contents($paths['thumb'],$expected);
    $stale = aicr_media_clean(req030(['id'=>$attachment,'save'=>true,'token'=>$preview['token']]));
    verify030(is_wp_error($stale) && $stale->get_error_data()['status'] === 409, 'Stale derivative rejects whole preview');
    verify030(file_get_contents($paths['main']) === $injected, 'Stale derivative leaves main file unchanged');
    file_put_contents($paths['thumb'],$injected);
    $preview = aicr_media_clean(req030(['id'=>$attachment]));
    $guard = fopen(get_temp_dir().'aicr-'.hash('sha256',ABSPATH.$attachment).'.lock','c');
    flock($guard,LOCK_EX);
    verify030(is_wp_error(aicr_media_clean(req030(['id'=>$attachment]))), 'Concurrent operation blocked by lock');
    flock($guard,LOCK_UN); fclose($guard);
    $saved = aicr_media_clean(req030(['id'=>$attachment,'save'=>true,'token'=>$preview['token']]));
    verify030(!is_wp_error($saved) && $saved['ok'] && $saved['remaining'] === 0 && count($saved['files']) === 4, 'In-place cleanup reports verified four-file success');
    foreach ($paths as $label=>$path) {
        verify030(file_get_contents($path) === $expected, "$label source pixel/container bytes preserved exactly");
        $decoded = imagecreatefrompng($path);
        verify030(imagesx($decoded) === 12 && imagesy($decoded) === 12 && imagecolorat($decoded,2,3) === imagecolorat($im,2,3), "$label decoded dimensions and sample pixel preserved");
        imagedestroy($decoded);
    }
    verify030(wp_get_attachment_url($attachment) === $url && get_attached_file($attachment) === $paths['main'], 'Same ID, filename and URL');
    verify030((int)wp_count_posts('attachment')->inherit === $attachmentCount, 'No new media attachments');
    $afterMeta = wp_get_attachment_metadata($attachment);
    verify030($afterMeta['filesize'] === strlen($expected) && $afterMeta['sizes']['thumbnail']['filesize'] === strlen($expected), 'Stored file sizes updated');
    verify030($afterMeta['image_meta'] === $metadata['image_meta'] && $afterMeta['original_image'] === $metadata['original_image'], 'Other attachment metadata unchanged');
    verify030(aicr_media_clean(req030(['id'=>$attachment]))['removed'] === 0, 'Fresh scan sees saved file contents');
    verify030(is_wp_error(aicr_media_clean(req030(['id'=>$attachment,'save'=>true,'token'=>$preview['token']]))), 'Consumed token cannot replay');
    // Legacy route can no longer create a copy, even without a replace parameter.
    file_put_contents($paths['main'],$injected);
    $legacyPreview = aicr_media_copy(req030(['id'=>$attachment]));
    $legacySaved = aicr_media_copy(req030(['id'=>$attachment,'save'=>true,'token'=>$legacyPreview['token']]));
    verify030($legacySaved['ok'] && (int)wp_count_posts('attachment')->inherit === $attachmentCount, 'Legacy media-copy save replaces in place');
    // A stale or corrupt proposal must not touch the original; temporary stage is cleaned up.
    $image = aicr_read_media($paths['main']);
    $image['hash'] = str_repeat('0',64);
    try { aicr_replace_media_file($image); verify030(false,'Stale atomic write accepted'); } catch (RuntimeException $e) { verify030(true,'Stale atomic write rejected'); }
    verify030(file_get_contents($paths['main']) === $expected, 'Stale atomic write leaves complete source');
    $image = aicr_read_media($paths['main']); $image['result']['data'] = 'invalid';
    try { aicr_replace_media_file($image); verify030(false,'Invalid image write accepted'); } catch (RuntimeException $e) { verify030(true,'Invalid staged image rejected'); }
    verify030(file_get_contents($paths['main']) === $expected && !glob(dirname($paths['main']).'/.aicr-*'), 'Invalid stage leaves original intact and no temporary copies');
    // Missing known files fail before writing any part of the attachment.
    $badMeta = $afterMeta; $badMeta['sizes']['missing'] = ['file'=>$prefix.'-missing.png','width'=>12,'height'=>12];
    wp_update_attachment_metadata($attachment,$badMeta);
    verify030(is_wp_error(aicr_media_clean(req030(['id'=>$attachment]))), 'Missing derivative reported');
    wp_update_attachment_metadata($attachment,$afterMeta);
    foreach (['jpeg'=>'image/jpeg','webp'=>'image/webp'] as $format=>$mime) {
        ob_start(); ('image'.$format)($im); $bytes=ob_get_clean();
        $bytes=aicr_strip_metadata($bytes,$mime)['data'];
        if ($format==='jpeg') $input=substr($bytes,0,2)."\xff\xfe".pack('n',6).'test'.substr($bytes,2);
        else { $body='WEBP'.substr($bytes,12).'XMP '.pack('V',4).'test'; $input='RIFF'.pack('V',strlen($body)).$body; }
        $upload=wp_upload_bits($prefix.'.'.$format,null,$input);
        $paths[$format]=$upload['file'];
        $image=aicr_read_media($upload['file']);
        $result=aicr_replace_media_file($image);
        verify030($result['verified'] && file_get_contents($upload['file'])===$bytes, "$format atomic replacement preserves all non-target bytes");
    }
} finally {
    if ($attachment) wp_delete_attachment($attachment,true);
    foreach ($paths as $path) if (is_file($path)) wp_delete_file($path);
    imagedestroy($im);
}
echo "Passed $count additional integration assertions.\n";
