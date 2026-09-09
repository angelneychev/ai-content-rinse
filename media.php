<?php
/** Lossless removal of textual image metadata; EXIF and colour profiles are preserved.
 * @package AI_Content_Rinse
 */
defined( 'ABSPATH' ) || exit;

/** Strip supported textual metadata without decoding pixels.
 * @param string $data Image bytes.
 * @param string $mime MIME type.
 * @return array Result.
 * @throws RuntimeException When the container is invalid.
 */
function aicr_strip_metadata( $data, $mime ) {
	$length = strlen( $data );
	$count = 0;
	if ( 'image/png' === $mime && "\x89PNG\r\n\x1a\n" === substr( $data, 0, 8 ) ) {
		$out = substr( $data, 0, 8 );
		$pos = 8;
		while ( $pos + 12 <= $length ) {
			$size = unpack( 'N', substr( $data, $pos, 4 ) )[1];
			$type = substr( $data, $pos + 4, 4 );
			if ( $size > $length - $pos - 12 ) {
				throw new RuntimeException( 'Invalid PNG chunk.' );
			}
			$chunk = substr( $data, $pos, $size + 12 );
			if ( hash( 'crc32b', substr( $chunk, 4, $size + 4 ) ) !== bin2hex( substr( $chunk, -4 ) ) ) {
				throw new RuntimeException( 'Invalid PNG checksum.' );
			}
			if ( in_array( $type, array( 'tEXt', 'zTXt', 'iTXt', 'caBX' ), true ) ) {
				++$count;
			} else {
				$out .= $chunk;
			}
			$pos += $size + 12;
			if ( 'IEND' === $type && $pos === $length ) {
				return array( 'data' => $out, 'removed' => $count );
			}
		}
	} elseif ( 'image/jpeg' === $mime && "\xff\xd8" === substr( $data, 0, 2 ) ) {
		$out = substr( $data, 0, 2 );
		$pos = 2;
		while ( $pos + 4 <= $length ) {
			$start = $pos;
			if ( 255 !== ord( $data[ $pos++ ] ) ) {
				break;
			}
			while ( $pos < $length && 255 === ord( $data[ $pos ] ) ) {
				++$pos;
			}
			if ( $pos + 3 > $length ) {
				break;
			}
			$marker = ord( $data[ $pos++ ] );
			if ( 218 === $marker ) {
				return array( 'data' => $out . substr( $data, $start ), 'removed' => $count );
			}
			$size = unpack( 'n', substr( $data, $pos, 2 ) )[1];
			if ( $size < 2 || $size > $length - $pos ) {
				break;
			}
			$body = substr( $data, $pos + 2, $size - 2 );
			$remove = 254 === $marker || ( 225 === $marker && ( str_starts_with( $body, "http://ns.adobe.com/xap/1.0/\0" ) || str_starts_with( $body, "http://ns.adobe.com/xmp/extension/\0" ) ) );
			if ( $remove ) {
				++$count;
			} else {
				$out .= substr( $data, $start, $pos + $size - $start );
			}
			$pos += $size;
		}
	} elseif ( 'image/webp' === $mime && 'RIFF' === substr( $data, 0, 4 ) && 'WEBP' === substr( $data, 8, 4 ) ) {
		if ( unpack( 'V', substr( $data, 4, 4 ) )[1] + 8 !== $length ) {
			throw new RuntimeException( 'Invalid WebP length.' );
		}
		$out = 'WEBP';
		$pos = 12;
		while ( $pos + 8 <= $length ) {
			$type = substr( $data, $pos, 4 );
			$size = unpack( 'V', substr( $data, $pos + 4, 4 ) )[1];
			$total = 8 + $size + ( $size % 2 );
			if ( $total > $length - $pos ) {
				break;
			}
			$chunk = substr( $data, $pos, $total );
			if ( 'XMP ' === $type ) {
				++$count;
			} else {
				if ( 'VP8X' === $type && $size >= 10 ) {
					$chunk[8] = chr( ord( $chunk[8] ) & ~4 );
				}
				$out .= $chunk;
			}
			$pos += $total;
		}
		if ( $pos === $length ) {
			return array( 'data' => 'RIFF' . pack( 'V', strlen( $out ) ) . $out, 'removed' => $count );
		}
	}
	throw new RuntimeException( 'Unsupported or malformed image.' );
}

/** Detect provenance-related signals without altering the source bytes.
 * @param string $data Image bytes.
 * @param string $mime MIME type.
 * @return array{detected:bool,hints:array<int,string>}
 */
function aicr_media_provenance_hints( $data, $mime ) {
	$hints = array();
	if ( 'image/png' === $mime && "\x89PNG\r\n\x1a\n" === substr( $data, 0, 8 ) ) {
		$length = strlen( $data );
		$pos    = 8;
		while ( $pos + 12 <= $length ) {
			$size = unpack( 'N', substr( $data, $pos, 4 ) )[1];
			$type = substr( $data, $pos + 4, 4 );
			if ( $size > $length - $pos - 12 ) {
				break;
			}
			$chunk_data = substr( $data, $pos + 8, $size );
			if ( 'caBX' === $type ) {
				$hints[] = 'C2PA manifest (caBX)';
				$readable = preg_replace( '/[^\x20-\x7E]+/', ' ', $chunk_data );
				if ( preg_match( '/(?:openai|chatgpt|gpt)/i', (string) $readable ) ) {
					$hints[] = 'OpenAI/GPT reference in C2PA metadata';
				}
			}
			$pos += $size + 12;
			if ( 'IEND' === $type ) {
				break;
			}
		}
	}
	return array( 'detected' => ! empty( $hints ), 'hints' => array_values( array_unique( $hints ) ) );
}

/** Enumerate existing attachment files, including resized and edit-backup versions.
 * @param int $id Attachment ID.
 * @return array File paths.
 */
function aicr_media_paths( $id ) {
	$path = get_attached_file( $id );
	$meta = wp_get_attachment_metadata( $id );
	$paths = array( $path );
	$dir = dirname( $path );
	if ( ! empty( $meta['original_image'] ) ) {
		$paths[] = $dir . '/' . $meta['original_image'];
	}
	foreach ( array( $meta['sizes'] ?? array(), get_post_meta( $id, '_wp_attachment_backup_sizes', true ) ?: array() ) as $sizes ) {
		foreach ( $sizes as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$paths[] = $dir . '/' . $size['file'];
			}
		}
	}
	return array_values( array_unique( $paths ) );
}

/** Read one validated local image.
 * @param string $path Candidate path.
 * @return array Validated image.
 * @throws RuntimeException When the file cannot be processed.
 */
function aicr_read_media( $path ) {
	$uploads = wp_upload_dir();
	$base = realpath( $uploads['basedir'] );
	$real = realpath( $path );
	if ( ! $base || ! $real || ! str_starts_with( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $base ) ) ) || ! is_file( $real ) || filesize( $real ) > 20 * MB_IN_BYTES ) {
		throw new RuntimeException( esc_html__( 'A local image up to 20 MB is required. A file may be missing or outside uploads.', 'ai-content-rinse' ) );
	}
	$data = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Validated local attachment.
	if ( false === $data ) {
		throw new RuntimeException( esc_html__( 'The image could not be read.', 'ai-content-rinse' ) );
	}
	$info = getimagesizefromstring( $data );
	if ( ! $info ) {
		throw new RuntimeException( esc_html__( 'The image dimensions could not be read.', 'ai-content-rinse' ) );
	}
	$result = aicr_strip_metadata( $data, $info['mime'] );
	return array( 'path' => $real, 'data' => $data, 'mime' => $info['mime'], 'hash' => hash( 'sha256', $data ), 'result' => $result );
}

/** Inspect every known file without regenerating image sizes.
 * @param int $id Attachment ID.
 * @return array Scan and a fingerprint of the complete file set.
 * @throws RuntimeException When any known file cannot be checked.
 */
function aicr_scan_media( $id ) {
	$paths = aicr_media_paths( $id );
	if ( count( $paths ) > 100 ) {
		throw new RuntimeException( esc_html__( 'This attachment has more than 100 file variants.', 'ai-content-rinse' ) );
	}
	$files = array();
	$hashes = array();
	$hints = array();
	$removed = 0;
	foreach ( $paths as $path ) {
		$image = aicr_read_media( $path );
		if ( isset( $hashes[ wp_normalize_path( $image['path'] ) ] ) ) {
			continue;
		}
		$signals = aicr_media_provenance_hints( $image['data'], $image['mime'] );
		$files[] = array( 'name' => basename( $path ), 'removed' => $image['result']['removed'], 'hints' => $signals['hints'] );
		$hashes[ wp_normalize_path( $image['path'] ) ] = $image['hash'];
		$removed += $image['result']['removed'];
		$hints = array_merge( $hints, $signals['hints'] );
	}
	ksort( $hashes );
	return array(
		'files' => $files,
		'hashes' => $hashes,
		'fingerprint' => hash( 'sha256', wp_json_encode( $hashes ) ),
		'removed' => $removed,
		'provenance' => array( 'detected' => ! empty( $hints ), 'hints' => array_values( array_unique( $hints ) ) ),
	);
}

/** Stage and verify bytes before replacing the same path.
 * A temporary staging file is removed on failure. No attachment or persistent copy is created.
 * @param array $image Validated source and proposed bytes.
 * @return array Post-write verification.
 * @throws RuntimeException When staging, replacement or verification fails.
 */
function aicr_replace_media_file( $image ) {
	$path = $image['path'];
	$bytes = $image['result']['data'];
	// phpcs:disable WordPress.WP.AlternativeFunctions -- Atomic local file replacement requires native I/O; the path is validated inside uploads.
	$temp = tempnam( dirname( $path ), '.aicr-' );
	if ( false === $temp || dirname( $temp ) !== dirname( $path ) ) {
		if ( $temp ) {
			unlink( $temp );
		}
		throw new RuntimeException( esc_html__( 'A temporary file could not be created beside the image.', 'ai-content-rinse' ) );
	}
	try {
		$written = file_put_contents( $temp, $bytes, LOCK_EX );
		if ( strlen( $bytes ) !== $written || hash_file( 'sha256', $temp ) !== hash( 'sha256', $bytes ) ) {
			throw new RuntimeException( __( 'The complete cleaned file could not be written. The original was not replaced.', 'ai-content-rinse' ) );
		}
		$before_info = getimagesizefromstring( $image['data'] );
		$after_info = getimagesize( $temp );
		if ( ! $after_info || $before_info[0] !== $after_info[0] || $before_info[1] !== $after_info[1] || $before_info['mime'] !== $after_info['mime'] ) {
			throw new RuntimeException( __( 'Image verification failed. The original was not replaced.', 'ai-content-rinse' ) );
		}
		if ( 0 !== aicr_strip_metadata( $bytes, $image['mime'] )['removed'] ) {
			throw new RuntimeException( __( 'Supported metadata remains. The original was not replaced.', 'ai-content-rinse' ) );
		}
		if ( hash_file( 'sha256', $path ) !== $image['hash'] ) {
			throw new RuntimeException( __( 'The image changed during cleaning. Scan again.', 'ai-content-rinse' ) );
		}
		$permissions = fileperms( $path );
		if ( false !== $permissions && ! chmod( $temp, $permissions & 0777 ) ) {
			throw new RuntimeException( __( 'File permissions could not be preserved.', 'ai-content-rinse' ) );
		}
		if ( ! rename( $temp, $path ) ) {
			throw new RuntimeException( __( 'The cleaned file could not replace the original. Try again when the file is not in use.', 'ai-content-rinse' ) );
		}
		clearstatcache( true, $path );
		$verified = aicr_read_media( $path );
		if ( $verified['hash'] !== hash( 'sha256', $bytes ) || 0 !== $verified['result']['removed'] ) {
			throw new RuntimeException( __( 'The file was replaced, but its final verification failed. Scan again.', 'ai-content-rinse' ) );
		}
		return array( 'name' => basename( $path ), 'removed' => $image['result']['removed'], 'remaining' => 0, 'verified' => true );
	} finally {
		if ( file_exists( $temp ) ) {
			unlink( $temp );
		}
	}
	// phpcs:enable WordPress.WP.AlternativeFunctions
}

/** Preview or clean the existing attachment files in place.
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error Result.
 */
function aicr_media_clean( $request ) {
	$id = absint( $request->get_param( 'id' ) );
	if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) || 'attachment' !== get_post_type( $id ) ) {
		return new WP_Error( 'aicr_media', __( 'This image is not available.', 'ai-content-rinse' ), array( 'status' => 403 ) );
	}
	// Serialize this plugin's writes. External edits are additionally checked by file hashes.
	$lock_path = get_temp_dir() . 'aicr-' . hash( 'sha256', ABSPATH . $id ) . '.lock';
	$lock = fopen( $lock_path, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Advisory lock in the system temporary directory.
	if ( ! $lock ) {
		return new WP_Error( 'aicr_lock', __( 'The attachment could not be locked.', 'ai-content-rinse' ), array( 'status' => 503 ) );
	}
	try {
		if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			return new WP_Error( 'aicr_busy', __( 'This attachment is being processed. Try again shortly.', 'ai-content-rinse' ), array( 'status' => 409 ) );
		}
		$scan = aicr_scan_media( $id );
		$key = 'aicr_media_' . get_current_user_id() . '_' . sanitize_key( (string) $request->get_param( 'token' ) );
		if ( ! $request->get_param( 'save' ) ) {
			$token = wp_generate_uuid4();
			set_transient( 'aicr_media_' . get_current_user_id() . '_' . $token, array( 'id' => $id, 'fingerprint' => $scan['fingerprint'] ), 15 * MINUTE_IN_SECONDS );
			unset( $scan['hashes'] );
			return array_merge( $scan, array( 'id' => $id, 'token' => $token, 'url' => wp_get_attachment_url( $id ) ) );
		}
		$proposal = get_transient( $key );
		if ( ! $proposal || $id !== $proposal['id'] || ! hash_equals( $proposal['fingerprint'], $scan['fingerprint'] ) ) {
			return new WP_Error( 'aicr_changed', __( 'The image files changed or the preview expired. Scan again.', 'ai-content-rinse' ), array( 'status' => 409 ) );
		}
		$results = array();
		$errors = array();
		foreach ( $scan['hashes'] as $path => $expected_hash ) {
			try {
				$image = aicr_read_media( $path );
				if ( ! hash_equals( $expected_hash, $image['hash'] ) ) {
					throw new RuntimeException( __( 'The image changed during cleaning. Scan again.', 'ai-content-rinse' ) );
				}
				$results[] = $image['result']['removed'] ? aicr_replace_media_file( $image ) : array( 'name' => basename( $path ), 'removed' => 0, 'remaining' => 0, 'verified' => true );
			} catch ( RuntimeException $error ) {
				$errors[] = array( 'name' => basename( $path ), 'message' => $error->getMessage() );
				break;
			}
		}
		delete_transient( $key );
		// Keep dimensions, references and all existing image sizes. Update byte sizes only.
		$meta = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) ) {
			clearstatcache();
			$meta['filesize'] = filesize( get_attached_file( $id ) );
			foreach ( $meta['sizes'] ?? array() as $name => $size ) {
				$size_path = dirname( get_attached_file( $id ) ) . '/' . $size['file'];
				if ( is_file( $size_path ) ) {
					$meta['sizes'][ $name ]['filesize'] = filesize( $size_path );
				}
			}
			wp_update_attachment_metadata( $id, $meta );
		}
		try {
			$after = aicr_scan_media( $id );
		} catch ( RuntimeException $error ) {
			$errors[] = array( 'name' => __( 'Final verification', 'ai-content-rinse' ), 'message' => $error->getMessage() );
			$after = array( 'removed' => null, 'provenance' => array( 'detected' => false, 'hints' => array() ) );
		}
		return array(
			'ok' => empty( $errors ) && 0 === $after['removed'],
			'id' => $id,
			'files' => $results,
			'errors' => $errors,
			'remaining' => $after['removed'],
			'provenance' => $after['provenance'],
			'url' => wp_get_attachment_url( $id ),
		);
	} catch ( RuntimeException $error ) {
		return new WP_Error( 'aicr_file', $error->getMessage(), array( 'status' => 400 ) );
	} finally {
		flock( $lock, LOCK_UN );
		fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close advisory lock.
	}
}

/** Keep the old route compatible with previews; every save now replaces in place.
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error Result.
 */
function aicr_media_copy( $request ) {
	return aicr_media_clean( $request );
}
