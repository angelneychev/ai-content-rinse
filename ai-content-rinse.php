<?php
/**
 * Plugin Name: AI Content Rinse: Text & Metadata Cleaner
 * Description: Review text cleanup and remove supported image metadata in place. Preview exact changes and verify cleaned files.
 * Version: 0.4.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Angel Neychev
 * Author URI: https://angelneychev.eu
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-content-rinse
 *
 * @package AI_Content_Rinse
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/includes.php';
add_action( 'admin_menu', 'aicr_admin_menu' );
add_action( 'admin_enqueue_scripts', 'aicr_assets' );
add_action( 'rest_api_init', 'aicr_routes' );
add_action( 'enqueue_block_editor_assets', 'aicr_editor_assets' );

/** Register the workspace. */
function aicr_admin_menu() {
	add_management_page( 'AI Content Rinse', 'AI Content Rinse', 'manage_options', 'ai-content-rinse', 'aicr_page' );
}

/** Enqueue only on the plugin workspace.
 * @param string $hook Admin screen.
 */
function aicr_assets( $hook ) {
	if ( 'tools_page_ai-content-rinse' !== $hook ) {
		return;
	}
	aicr_common_assets();
	wp_enqueue_style( 'aicr-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), '0.4.0' );
	wp_enqueue_script( 'aicr-admin', plugins_url( 'assets/admin.js', __FILE__ ), array( 'aicr-common' ), '0.4.0', true );
	wp_set_script_translations( 'aicr-admin', 'ai-content-rinse' );

}

/** Render the workspace shell. */
function aicr_page() {
	?>
	<div class="wrap aicr">
		<header><span class="aicr-eyebrow">AI CONTENT RINSE / 0.4.0</span><h1><?php esc_html_e( 'Review and clean your content.', 'ai-content-rinse' ); ?></h1>
		<p><?php esc_html_e( 'Preview text changes and remove supported image metadata.', 'ai-content-rinse' ); ?></p></header>
		<nav aria-label="<?php esc_attr_e( 'Workspace', 'ai-content-rinse' ); ?>">
			<button class="button button-primary" data-tab="text"><?php esc_html_e( 'Text', 'ai-content-rinse' ); ?></button>
			<button class="button" data-tab="paste"><?php esc_html_e( 'Paste text', 'ai-content-rinse' ); ?></button>
			<button class="button" data-tab="media"><?php esc_html_e( 'Media', 'ai-content-rinse' ); ?></button>
			<button class="button" data-tab="history"><?php esc_html_e( 'History', 'ai-content-rinse' ); ?></button>
		</nav>
		<p class="aicr-note"><?php esc_html_e( 'Processing stays on your site. Findings are not proof of AI authorship. No AI provider or API key is required.', 'ai-content-rinse' ); ?></p>
		<div id="aicr-status" role="status" aria-live="polite"></div>
		<div id="aicr-controls" class="aicr-controls"></div>
		<section id="aicr-list"></section>
		<div id="aicr-pager"></div>
		<section id="aicr-preview" tabindex="-1" hidden></section>
		<footer>Angel Neychev - <a href="https://angelneychev.eu">angelneychev.eu</a></footer>
	</div>
	<?php
}

/** Shared preview rendering, transport and per-user preferences. */
function aicr_common_assets() {
	wp_enqueue_script( 'aicr-common', plugins_url( 'assets/common.js', __FILE__ ), array( 'wp-i18n' ), '0.4.0', true );
	wp_set_script_translations( 'aicr-common', 'ai-content-rinse' );
	$rest_root = add_query_arg( 'rest_route', '/ai-content-rinse/v1/', home_url( '/' ) );
	wp_localize_script( 'aicr-common', 'aicrConfig', array( 'root' => $rest_root, 'nonce' => wp_create_nonce( 'wp_rest' ), 'rules' => aicr_preferences() ) );
}

/** Add reviewed cleanup to the post/page block editor for administrators. */
function aicr_editor_assets() {
	$screen = get_current_screen();
	if ( ! $screen || ! $screen->is_block_editor() || ! in_array( $screen->post_type, array( 'post', 'page' ), true ) || ! aicr_permission() ) {
		return;
	}
	aicr_common_assets();
	wp_enqueue_style( 'aicr-editor', plugins_url( 'assets/admin.css', __FILE__ ), array(), '0.4.0' );
	wp_enqueue_script( 'aicr-editor', plugins_url( 'assets/editor.js', __FILE__ ), array( 'aicr-common', 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-blocks' ), '0.4.0', true );
	wp_set_script_translations( 'aicr-editor', 'ai-content-rinse' );
}
