<?php
/**
 * Plugin Name: SEO Copilot
 * Description: Google Search ConsoleとAIを活用して、WordPressサイトのSEO改善候補・AI提案・LLMO/AEOチェック・効果測定を支援します。
 * Version:     1.0.0
 * Author:      あてにゃん
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: seo-copilot
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEOCP_VERSION', '1.0.0' );
define( 'SEOCP_PLUGIN_FILE', __FILE__ );
define( 'SEOCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEOCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SEOCP_TABLE_LLMO', 'seocp_llmo_results' );
define( 'SEOCP_TABLE_PAGES', 'seocp_page_stats' );
define( 'SEOCP_TABLE_SUGGESTIONS', 'seocp_suggestions' );
define( 'SEOCP_TABLE_INDEX', 'seocp_index_status' );
define( 'SEOCP_TABLE_EFFECT', 'seocp_effect_tracking' );

require_once SEOCP_PLUGIN_DIR . 'includes/class-seocp-activator.php';
register_activation_hook( __FILE__, array( 'SEOCP_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SEOCP_Activator', 'deactivate' ) );

require_once SEOCP_PLUGIN_DIR . 'includes/class-seocp-settings.php';
require_once SEOCP_PLUGIN_DIR . 'includes/class-seocp-diff.php';
require_once SEOCP_PLUGIN_DIR . 'includes/class-seocp-ai-provider.php';
require_once SEOCP_PLUGIN_DIR . 'includes/class-seocp-llmo-checker.php';
require_once SEOCP_PLUGIN_DIR . 'includes/class-seocp-gsc-api.php';
require_once SEOCP_PLUGIN_DIR . 'includes/class-seocp-suggestion-engine.php';
require_once SEOCP_PLUGIN_DIR . 'includes/class-seocp-meta-description.php';
require_once SEOCP_PLUGIN_DIR . 'includes/class-seocp-draft-generator.php';
require_once SEOCP_PLUGIN_DIR . 'includes/class-seocp-content-applier.php';
require_once SEOCP_PLUGIN_DIR . 'includes/class-seocp-index-checker.php';
require_once SEOCP_PLUGIN_DIR . 'includes/class-seocp-effect-tracker.php';
require_once SEOCP_PLUGIN_DIR . 'admin/class-seocp-admin.php';

function seocp_init() {
	new SEOCP_Settings();
	new SEOCP_GSC_API();
	new SEOCP_Meta_Description();
	new SEOCP_Admin();
	seocp_maybe_upgrade();
}
add_action( 'plugins_loaded', 'seocp_init' );

function seocp_maybe_upgrade() {
	$current = get_option( 'seocp_db_version', '' );
	if ( $current !== SEOCP_VERSION ) {
		SEOCP_Activator::activate();
		update_option( 'seocp_db_version', SEOCP_VERSION );
	}
}
