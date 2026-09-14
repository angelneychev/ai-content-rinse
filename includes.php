<?php
/** Processing and REST endpoints.
 * @package AI_Content_Rinse
 */
defined( 'ABSPATH' ) || exit;

/** Restrict the workspace to administrators. */
function aicr_permission() {
	return current_user_can( 'manage_options' );
}

/** Register authenticated routes. */
function aicr_routes() {
	foreach ( array( 'items' => 'GET', 'preview' => 'POST', 'paste' => 'POST', 'editor-preview' => 'POST', 'settings' => 'GET,POST', 'apply' => 'POST', 'restore' => 'POST', 'media-clean' => 'POST', 'media-copy' => 'POST' ) as $route => $method ) {
		register_rest_route( 'ai-content-rinse/v1', '/' . $route, array( 'methods' => $method, 'callback' => 'aicr_' . str_replace( '-', '_', $route ), 'permission_callback' => 'aicr_permission' ) );
	}
}

/** Read the current user's cleanup preferences. */
function aicr_preferences() {
	$saved = get_user_meta( get_current_user_id(), '_aicr_rules', true );
	$defaults = array( 'invisible' => true, 'dashes' => true );
	return is_array( $saved ) ? array_intersect_key( $saved, $defaults ) + $defaults : $defaults;
}

/** Validate explicit rules, or use the current user's preferences.
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error Rules.
 */
function aicr_request_rules( $request ) {
	$rules = $request->get_param( 'rules' );
	if ( null === $rules ) {
		return aicr_preferences();
	}
	if ( ! is_array( $rules ) || count( $rules ) !== 2 || ! isset( $rules['invisible'], $rules['dashes'] ) || ! is_bool( $rules['invisible'] ) || ! is_bool( $rules['dashes'] ) ) {
		return new WP_Error( 'aicr_rules', __( 'Choose true or false for both cleanup settings.', 'ai-content-rinse' ), array( 'status' => 400 ) );
	}
	return $rules;
}

/** Get or save per-user cleanup settings.
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error Rules.
 */
function aicr_settings( $request ) {
	$rules = aicr_request_rules( $request );
	if ( is_wp_error( $rules ) ) {
		return $rules;
	}
	if ( 'POST' === $request->get_method() ) {
		update_user_meta( get_current_user_id(), '_aicr_rules', $rules );
		if ( aicr_preferences() !== $rules ) {
			return new WP_Error( 'aicr_settings', __( 'Could not save cleanup settings. Try again.', 'ai-content-rinse' ), array( 'status' => 500 ) );
		}
	}
	return $rules;
}

/** Clean text nodes only, preserving tags, comments, code, emoji joiners and Cyrillic.
 * @param string $text Source.
 * @param bool   $plain Whether markup should be treated as plain text.
 * @param array  $rules Enabled cleanup categories.
 * @return array Result and counts.
 */
function aicr_clean_text( $text, $plain = false, $rules = array() ) {
	$rules += array( 'invisible' => true, 'dashes' => true );
	$counts = array();
	$segments = array();
	// Do not touch block comments, attributes, shortcodes or code-like elements.
	$tag_body = '(?:"[^"]*"|\'[^\']*\'|[^\'">])*';
	$pieces = $plain ? array( $text ) : preg_split( '~(<!--.*?-->|<(?:script|style|pre|code|textarea)\b' . $tag_body . '>.*?</(?:script|style|pre|code|textarea)\s*>|<' . $tag_body . '>|\[[^\]]*\])~isu', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( false === $pieces ) {
		return array( 'text' => $text, 'counts' => array(), 'segments' => array( array( 'before' => $text, 'after' => $text, 'label' => '' ) ) );
	}
	// These are invisible/control characters commonly introduced by copied text.
	// Keep U+200C/U+200D (ZWNJ/ZWJ): they are meaningful in scripts and emoji.
	$map = array(
		"—" => 'U+2014 (em dash)',
		"–" => 'U+2013 (en dash)',
		"\u{00AD}" => 'U+00AD',
		"\u{034F}" => 'U+034F',
		"\u{061C}" => 'U+061C',
		"\u{115F}" => 'U+115F',
		"\u{1160}" => 'U+1160',
		"\u{17B4}" => 'U+17B4',
		"\u{17B5}" => 'U+17B5',
		"\u{180B}" => 'U+180B',
		"\u{180C}" => 'U+180C',
		"\u{180D}" => 'U+180D',
		"\u{180E}" => 'U+180E',
		"\u{180F}" => 'U+180F',
		"\u{200B}" => 'U+200B',
		"\u{200E}" => 'U+200E',
		"\u{200F}" => 'U+200F',
		"\u{202A}" => 'U+202A-U+202E',
		"\u{202B}" => 'U+202A-U+202E',
		"\u{202C}" => 'U+202A-U+202E',
		"\u{202D}" => 'U+202A-U+202E',
		"\u{202E}" => 'U+202A-U+202E',
		"\u{2060}" => 'U+2060-U+206F',
		"\u{2061}" => 'U+2060-U+206F',
		"\u{2062}" => 'U+2060-U+206F',
		"\u{2063}" => 'U+2060-U+206F',
		"\u{2064}" => 'U+2060-U+206F',
		"\u{2065}" => 'U+2060-U+206F',
		"\u{2066}" => 'U+2060-U+206F',
		"\u{2067}" => 'U+2060-U+206F',
		"\u{2068}" => 'U+2060-U+206F',
		"\u{2069}" => 'U+2060-U+206F',
		"\u{206A}" => 'U+2060-U+206F',
		"\u{206B}" => 'U+2060-U+206F',
		"\u{206C}" => 'U+2060-U+206F',
		"\u{206D}" => 'U+2060-U+206F',
		"\u{206E}" => 'U+2060-U+206F',
		"\u{206F}" => 'U+2060-U+206F',
		"\u{3164}" => 'U+3164',
		"\u{FEFF}" => 'U+FEFF',
		"\u{FFF9}" => 'U+FFF9-U+FFFB',
		"\u{FFFA}" => 'U+FFF9-U+FFFB',
		"\u{FFFB}" => 'U+FFF9-U+FFFB',
		"\u{FFA0}" => 'U+FFA0',
	);
	foreach ( array_keys( $map ) as $char ) {
		$is_dash = in_array( $char, array( '—', '–' ), true );
		if ( ! $rules[ $is_dash ? 'dashes' : 'invisible' ] ) {
			unset( $map[ $char ] );
		}
	}
	$characters = implode( '', array_keys( $map ) ) . ( $rules['invisible'] ? '\x{E0000}-\x{E0FFF}\x{FFF0}-\x{FFF8}' : '' );
	$pattern = '' === $characters ? '/(?!)/u' : '/[' . $characters . ']/u';
	foreach ( $pieces as $index => $piece ) {
		if ( 1 === $index % 2 ) {
			$segments[] = array( 'before' => $piece, 'after' => $piece, 'label' => '' );
			continue;
		}
		preg_match_all( $pattern, $piece, $matches, PREG_OFFSET_CAPTURE );
		$offset = 0;
		$cleaned = '';
		foreach ( $matches[0] as $match ) {
			$unchanged = substr( $piece, $offset, $match[1] - $offset );
			if ( '' !== $unchanged ) {
				$segments[] = array( 'before' => $unchanged, 'after' => $unchanged, 'label' => '' );
			}
			$char = $match[0];
			$octets = array_values( unpack( 'C*', $char ) );
			$codepoint = $octets[0] & ( 0x7f >> count( $octets ) );
			foreach ( array_slice( $octets, 1 ) as $octet ) {
				$codepoint = ( $codepoint << 6 ) | ( $octet & 0x3f );
			}
			$label = 'U+' . strtoupper( str_pad( dechex( $codepoint ), 4, '0', STR_PAD_LEFT ) );
			$category = $map[ $char ] ?? $label;
			$counts[ $category ] = ( $counts[ $category ] ?? 0 ) + 1;
			$replacement = in_array( $char, array( '—', '–' ), true ) ? '-' : '';
			$segments[] = array( 'before' => $char, 'after' => $replacement, 'label' => $label );
			$cleaned .= $unchanged . $replacement;
			$offset = $match[1] + strlen( $char );
		}
		$tail = substr( $piece, $offset );
		$segments[] = array( 'before' => $tail, 'after' => $tail, 'label' => '' );
		$pieces[ $index ] = $cleaned . $tail;
	}
	return array( 'text' => implode( '', $pieces ), 'counts' => $counts, 'segments' => $segments );
}

/** Clean pasted content without creating a post or recovery record.
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error Result.
 */
function aicr_paste( $request ) {
	$rules = aicr_request_rules( $request );
	if ( is_wp_error( $rules ) ) {
		return $rules;
	}
	$text = $request->get_param( 'text' );
	if ( ! is_string( $text ) || strlen( $text ) > MB_IN_BYTES || ! preg_match( '//u', $text ) ) {
		return new WP_Error( 'aicr_text', __( 'Enter valid text up to 1 MB.', 'ai-content-rinse' ), array( 'status' => 400 ) );
	}
	return aicr_clean_text( $text, 'plain' === $request->get_param( 'format' ), $rules );
}

/** Preview the editor's unsaved fields without writing to the database.
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error Preview.
 */
function aicr_editor_preview( $request ) {
	$post = aicr_post( $request->get_param( 'id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$rules = aicr_request_rules( $request );
	if ( is_wp_error( $rules ) ) {
		return $rules;
	}
	$fields = $request->get_param( 'fields' );
	$size = 0;
	foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $key ) {
		if ( ! is_array( $fields ) || ! isset( $fields[ $key ] ) || ! is_string( $fields[ $key ] ) || ! preg_match( '//u', $fields[ $key ] ) ) {
			return new WP_Error( 'aicr_text', __( 'The editor must supply valid title, content and excerpt text.', 'ai-content-rinse' ), array( 'status' => 400 ) );
		}
		$size += strlen( $fields[ $key ] );
	}
	if ( $size > MB_IN_BYTES || count( $fields ) !== 3 ) {
		return new WP_Error( 'aicr_text', __( 'Editor cleanup supports up to 1 MB of text.', 'ai-content-rinse' ), array( 'status' => 400 ) );
	}
	$result = array( 'before' => $fields, 'after' => array(), 'counts' => array(), 'segments' => array() );
	foreach ( $fields as $key => $value ) {
		$clean = aicr_clean_text( $value, false, $rules );
		$result['after'][ $key ] = $clean['text'];
		$result['counts'][ $key ] = $clean['counts'];
		$result['segments'][ $key ] = $clean['segments'];
	}
	$result['changed'] = $result['before'] !== $result['after'];
	return $result;
}

/** Read supported editable content.
 * @param int $id Post ID.
 * @return WP_Post|WP_Error Post or error.
 */
function aicr_post( $id ) {
	$post = get_post( absint( $id ) );
	if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
		return new WP_Error( 'aicr_post', __( 'This content is not available for editing.', 'ai-content-rinse' ), array( 'status' => 403 ) );
	}
	// Builder data and bound blocks can source text outside post_content.
	if ( get_post_meta( $post->ID, '_elementor_edit_mode', true ) || get_post_meta( $post->ID, '_et_pb_use_builder', true ) || get_post_meta( $post->ID, '_wpb_vc_js_status', true ) ) {
		return new WP_Error( 'aicr_builder', __( 'Page-builder content is not supported in this version.', 'ai-content-rinse' ), array( 'status' => 400 ) );
	}
	return $post;
}

/** Snapshot relevant fields.
 * @param WP_Post $post Post.
 * @return array Fields.
 */
function aicr_fields( $post ) {
	return array( 'post_title' => $post->post_title, 'post_content' => $post->post_content, 'post_excerpt' => $post->post_excerpt );
}

/** List a bounded page of records.
 * @param WP_REST_Request $request Request.
 * @return array Results.
 */
function aicr_items( $request ) {
	$mode = sanitize_key( (string) $request->get_param( 'mode' ) );
	$page = max( 1, absint( $request->get_param( 'page' ) ) );
	$args = array( 'post_type' => 'media' === $mode ? 'attachment' : array( 'post', 'page' ), 'post_status' => 'media' === $mode ? 'inherit' : array( 'publish', 'draft', 'pending', 'private', 'future' ), 'posts_per_page' => 20, 'paged' => $page, 'orderby' => 'ID', 'order' => 'DESC' );
	$args['s'] = sanitize_text_field( (string) $request->get_param( 'search' ) );
	if ( 'media' === $mode ) {
		$args['post_mime_type'] = array( 'image/jpeg', 'image/png', 'image/webp' );
	}
	if ( 'history' === $mode ) {
		$args['meta_key'] = '_aicr_backup'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded administrative recovery listing.
	}
	$query = new WP_Query( $args );
	$items = array();
	foreach ( $query->posts as $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			continue;
		}
		$items[] = array( 'id' => $post->ID, 'title' => $post->post_title, 'type' => $post->post_type, 'status' => $post->post_status, 'image' => 'media' === $mode ? wp_get_attachment_image_url( $post->ID, 'thumbnail' ) : '', 'backup' => (bool) get_post_meta( $post->ID, '_aicr_backup', true ) );
	}
	return array( 'items' => $items, 'pages' => (int) $query->max_num_pages );
}

/** Build preview on the server; store exact proposal per user.
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error Preview.
 */
function aicr_preview( $request ) {
	$rules = aicr_request_rules( $request );
	if ( is_wp_error( $rules ) ) {
		return $rules;
	}
	$post = aicr_post( $request->get_param( 'id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$before = aicr_fields( $post );
	$after = array();
	$counts = array();
	$segments = array();
	foreach ( $before as $key => $value ) {
		$result = aicr_clean_text( $value, false, $rules );
		$after[ $key ] = $result['text'];
		$counts[ $key ] = $result['counts'];
		$segments[ $key ] = $result['segments'];
	}
	$token = wp_generate_uuid4();
	set_transient( 'aicr_' . get_current_user_id() . '_' . $token, array( 'id' => $post->ID, 'before' => $before, 'after' => $after ), 15 * MINUTE_IN_SECONDS );
	return array( 'id' => $post->ID, 'title' => $post->post_title, 'before' => $before, 'after' => $after, 'counts' => $counts, 'segments' => $segments, 'token' => $token, 'changed' => $before !== $after );
}

/** Apply only the exact reviewed version.
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error Result.
 */
function aicr_apply( $request ) {
	$key = 'aicr_' . get_current_user_id() . '_' . sanitize_key( (string) $request->get_param( 'token' ) );
	$proposal = get_transient( $key );
	if ( ! $proposal ) {
		return new WP_Error( 'aicr_expired', __( 'Preview expired. Scan again.', 'ai-content-rinse' ), array( 'status' => 409 ) );
	}
	$post = aicr_post( $proposal['id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( aicr_fields( $post ) !== $proposal['before'] || get_post_meta( $post->ID, '_aicr_backup', true ) ) {
		return new WP_Error( 'aicr_conflict', __( 'Content changed or already has a rinse backup. Restore it before cleaning again.', 'ai-content-rinse' ), array( 'status' => 409 ) );
	}
	if ( $proposal['before'] === $proposal['after'] ) {
		return array( 'ok' => true );
	}
	$backup = array( 'before' => $proposal['before'], 'after' => $proposal['after'], 'time' => time(), 'user' => get_current_user_id() );
	if ( ! add_post_meta( $post->ID, '_aicr_backup', wp_slash( $backup ), true ) ) {
		return new WP_Error( 'aicr_backup', __( 'Could not save a recovery copy.', 'ai-content-rinse' ), array( 'status' => 500 ) );
	}
	$result = wp_update_post( wp_slash( array_merge( array( 'ID' => $post->ID ), $proposal['after'] ) ), true );
	if ( is_wp_error( $result ) ) {
		delete_post_meta( $post->ID, '_aicr_backup' );
		return $result;
	}
	$backup['after'] = aicr_fields( get_post( $post->ID ) );
	update_post_meta( $post->ID, '_aicr_backup', wp_slash( $backup ) );
	delete_transient( $key );
	return array( 'ok' => true );
}

/** Restore without overwriting later editorial changes.
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error Result.
 */
function aicr_restore( $request ) {
	$post = aicr_post( $request->get_param( 'id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$backup = get_post_meta( $post->ID, '_aicr_backup', true );
	if ( ! $backup || aicr_fields( $post ) !== $backup['after'] ) {
		return new WP_Error( 'aicr_conflict', __( 'No matching backup, or the content was edited after cleaning. Use WordPress revisions to review later edits.', 'ai-content-rinse' ), array( 'status' => 409 ) );
	}
	$result = wp_update_post( wp_slash( array_merge( array( 'ID' => $post->ID ), $backup['before'] ) ), true );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	delete_post_meta( $post->ID, '_aicr_backup' );
	return array( 'ok' => true );
}

require_once __DIR__ . '/media.php';
