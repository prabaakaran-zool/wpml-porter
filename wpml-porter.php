<?php
/**
 * Plugin Name:       WPML Porter
 * Plugin URI:        https://github.com/your-repo/wpml-porter
 * Description:       Import & export any Custom Post Type with taxonomies, custom fields, and full WPML translation linking. No coding required.
 * Version:           1.3.8
 * Author:            Your Name
 * License:           GPL-2.0+
 * Text Domain:       wpml-porter
 */

defined( 'ABSPATH' ) || exit;

define( 'WPML_PORTER_VERSION', '1.3.8' );
define( 'WPML_PORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPML_PORTER_URL', plugin_dir_url( __FILE__ ) );
define( 'WPML_PORTER_BASENAME', plugin_basename( __FILE__ ) );

require_once WPML_PORTER_DIR . 'includes/class-wpml-porter-exporter.php';
require_once WPML_PORTER_DIR . 'includes/class-wpml-porter-importer.php';
require_once WPML_PORTER_DIR . 'includes/class-wpml-porter-terms.php';
require_once WPML_PORTER_DIR . 'includes/class-wpml-porter-package.php';
require_once WPML_PORTER_DIR . 'includes/class-wpml-porter-admin.php';

/**
 * Load WPML Translation Editor auto-complete JavaScript.
 */
add_action( 'admin_enqueue_scripts', 'wpml_porter_enqueue_translation_editor_script' );

function wpml_porter_enqueue_translation_editor_script() {

    if ( ! is_admin() ) {
        return;
    }

    $script_path = WPML_PORTER_DIR . 'assets/js/wpml-auto-complete.js';

    // Don't load if the JS file doesn't exist.
    if ( ! file_exists( $script_path ) ) {
        return;
    }

    wp_enqueue_script(
        'wpml-porter-auto-complete',
        WPML_PORTER_URL . 'assets/js/wpml-auto-complete.js',
        array( 'jquery' ),
        filemtime( $script_path ),
        true
    );
}

/**
 * Keep ACF entirely database-only, everywhere on this site.
 *
 * By default ACF auto-writes/reads a matching JSON file (in the theme's
 * acf-json folder) for every post type, taxonomy, and field group whenever
 * one is saved — this is what makes the "Local JSON" column show
 * "Saved" / "Awaiting save" in ACF's admin lists, and it's exactly the
 * behaviour this plugin's imports are meant to avoid.
 *
 * Disabling both the save path and the load path turns Local JSON off
 * completely: ACF stops writing JSON files on save, stops scanning for
 * existing ones, and the "Local JSON" column no longer applies to anything.
 * Everything — including items created directly through the ACF UI, not
 * just ones imported by this plugin — is then stored and read only from
 * the database, which is the "ACF only" behaviour requested.
 *
 * Note: this is a site-wide setting. If any existing JSON files remain in
 * an acf-json folder from before, they'll simply be ignored going forward
 * (safe to delete manually if you want to tidy up).
 */
add_filter( 'acf/settings/save_json', '__return_false' );
add_filter( 'acf/settings/load_json', '__return_empty_array' );

/**
 * Bootstrap
 */
function wpml_porter_init() {
    new WPML_Porter_Admin();
}
add_action( 'plugins_loaded', 'wpml_porter_init' );
