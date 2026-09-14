<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/bootstrap.php';
$checks = 0;
function check040($ok, $label) {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
function request040($route, $params = [], $method = 'POST') {
    $r = new WP_REST_Request($method, '/ai-content-rinse/v1/' . $route);
    $r->set_body_params($params);
    return rest_do_request($r);
}
$admin = get_user_by('login', 'administrator')->ID;
wp_set_current_user($admin);
$old = get_user_meta($admin, '_aicr_rules', true);
$id = 0;
try {
    delete_user_meta($admin, '_aicr_rules');
    $text = "a\u{200B}b—c–d\u{E0100}";
    foreach ([[true,true,'ab-c-d'], [true,false,'ab—c–d'], [false,true,"a\u{200B}b-c-d\u{E0100}"], [false,false,$text]] as [$invisible,$dashes,$expected]) {
        $rules = compact('invisible','dashes');
        $result = aicr_clean_text($text, true, $rules);
        check040($result['text'] === $expected, 'Independent rule combination');
        check040(implode('', array_column($result['segments'],'after')) === $expected, 'Exact after segments for each combination');
        check040(implode('', array_column($result['segments'],'before')) === $text, 'Original segments for each combination');
    }
    $html = "<!-- wp:paragraph --><p title=\"—\">a\u{200B}—👨‍👩‍👧</p><code>—\u{200B}</code><!-- /wp:paragraph -->";
    $clean = aicr_clean_text($html, false, ['invisible'=>true, 'dashes'=>false]);
    check040($clean['text'] === str_replace("a\u{200B}", 'a', $html), 'Markup, code, emoji and disabled dashes preserved');
    $rules = ['invisible'=>true, 'dashes'=>false];
    check040(request040('settings',['rules'=>$rules])->get_data() === $rules, 'Save preferences');
    check040(request040('settings',[], 'GET')->get_data() === $rules, 'Read saved preferences');
    check040(request040('paste',['text'=>$text, 'format'=>'plain'])->get_data()['text'] === 'ab—c–d', 'Saved preferences drive default scans');
    check040(request040('settings',['rules'=>['invisible'=>'false','dashes'=>false]])->get_status() === 400, 'Reject ambiguous boolean strings');
    check040(request040('settings',['rules'=>['dashes'=>true]])->get_status() === 400, 'Reject incomplete rules');
    $id = wp_insert_post(['post_title'=>'Stored title', 'post_content'=>'Stored content', 'post_status'=>'draft', 'post_author'=>$admin], true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    $fields = ['post_title'=>$text, 'post_content'=>$html, 'post_excerpt'=>$text];
    $result = request040('editor-preview',['id'=>$id,'fields'=>$fields,'rules'=>['invisible'=>true,'dashes'=>true]]);
    check040($result->get_status() === 200 && $result->get_data()['changed'], 'Preview unsaved editor fields');
    check040($result->get_data()['before'] === $fields, 'Preview uses supplied unsaved text');
    check040(get_post($id)->post_title === 'Stored title' && get_post($id)->post_content === 'Stored content', 'Editor preview never writes post');
    check040(!get_post_meta($id,'_aicr_backup',true), 'No editor preview recovery record');
    $bad = $fields; $bad['post_content'] = str_repeat('x', MB_IN_BYTES);
    check040(request040('editor-preview',['id'=>$id,'fields'=>$bad])->get_status() === 400, 'Limit aggregate editor input');
    check040(request040('editor-preview',['id'=>$id,'fields'=>['post_content'=>'text']])->get_status() === 400, 'Reject missing fields');
    $bad = $fields; $bad['post_title'] = "\xff";
    check040(request040('editor-preview',['id'=>$id,'fields'=>$bad])->get_status() === 400, 'Reject invalid UTF-8 editor input');
    update_post_meta($id,'_elementor_edit_mode','builder');
    check040(request040('editor-preview',['id'=>$id,'fields'=>$fields])->get_status() === 400, 'Reject builder post');
    delete_post_meta($id,'_elementor_edit_mode');
    wp_update_post(['ID'=>$id,'post_content'=>$text]);
    $preview = request040('preview',['id'=>$id,'rules'=>$rules])->get_data();
    check040($preview['after']['post_content'] === 'ab—c–d', 'Workspace preview respects explicit rules');
    request040('settings',['rules'=>['invisible'=>false,'dashes'=>true]]);
    check040(request040('apply',['token'=>$preview['token']])->get_status() === 200, 'Apply frozen preview after preferences change');
    check040(get_post($id)->post_content === 'ab—c–d', 'Saved content is exactly reviewed proposal');
    check040(request040('restore',['id'=>$id])->get_status() === 200 && get_post($id)->post_content === $text, 'Restore original mixed characters');
    wp_set_current_user(0);
    foreach (['settings','editor-preview','preview','paste'] as $route) {
        check040(request040($route,['id'=>$id,'fields'=>$fields])->get_status() === 401, 'Anonymous access denied: '.$route);
    }
    check040(aicr_preferences() === ['invisible'=>true,'dashes'=>true], 'Preferences isolated from another user');
} finally {
    wp_set_current_user($admin);
    if ($id && !is_wp_error($id)) wp_delete_post($id,true);
    if (is_array($old)) update_user_meta($admin,'_aicr_rules',$old); else delete_user_meta($admin,'_aicr_rules');
}
echo "Passed $checks v0.4.0 assertions.\n";
