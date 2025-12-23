<?php
/**
 * PHPUnit bootstrap file for multisite tests
 */

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Wayback_Machine\HTTP_Client\HTTP_Snapshot_Client;

// Composer autoloader must be loaded before WP_PHPUNIT__DIR will be available
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Give access to tests_add_filter() function.
require_once getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php';

// Load all environment variables into $_ENV
try {
	$dotenv = Dotenv\Dotenv::createUnsafeImmutable( __DIR__ );
	$dotenv->load();
} catch ( \Throwable $th ) {
	// Do nothing if fails to find env as not used in pipeline.
}

define( 'FIXTURES_PATH', __DIR__ . '/Fixtures' );

// Custom error handler to ignore specific errors.
$previous_error_handler = set_error_handler(
	function ( $errno, $errstr, $errfile, $errline ) use ( &$previous_error_handler ) {
		// Ignore translation early errors, we need to do this before init to allow the action scheduler to load correctly.
		if ( str_starts_with( $errstr, 'Function _load_textdomain_just_in_time was called' ) ) {
			return true;
		}
		if ( $previous_error_handler ) {
			return call_user_func( $previous_error_handler, $errno, $errstr, $errfile, $errline );
		}
		return false;
	}
);

tests_add_filter(
	'muplugins_loaded',
	function () {

		$GLOBALS['wp_theme_directories'] = array( dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit/data/themedir1' );

		// Activate the plugin.
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		activate_plugin( 'internet-archive-wayback-machine-link-fixer/internet-archive-wayback-machine-link-fixer.php' );

		// include the function file
		include_once dirname( __DIR__ ) . '/functions.php';

		// Denote if we should skip live API tests.
		$GLOBALS['iawmlf_skip_live_api_tests'] = false;

		// Get the snapshot client.
		$client = new HTTP_Snapshot_Client();
		$online = $client->is_online();

		//If not online, skip live API tests.
		if ( ! $online ) {
			$GLOBALS['iawmlf_skip_live_api_tests'] = true;
		}

		// Ensure the settings to process links is enabled.
		update_option( Settings::PROCESS_LINKS, true );

		// Ensure onboarding is marked as complete to prevent it interfering with tests.
		update_option( Settings::POST_ACTIVATION_ONBOARDING_KEY, Settings::ONBOARDING_COMPLETED_OPTION );
	}
);

// Create test sites for multisite tests after WordPress is fully loaded.
tests_add_filter(
	'init',
	function () {
		if ( ! is_multisite() ) {
			return;
		}

		// Initialize the test sites array.
		$GLOBALS['iawmlf_test_sites'] = array();

		// Include necessary WordPress functions.
		require_once ABSPATH . 'wp-admin/includes/ms.php';

		// Get the test domain.
		$domain = defined( 'WP_TESTS_DOMAIN' ) ? WP_TESTS_DOMAIN : 'example.org';

		// Main site (blog_id 1) is Dexter.
		$GLOBALS['iawmlf_test_sites']['dexter'] = 1;

		// Create Garren site (sub-site).
		$garren_path = '/garren';
		$garren_site_id = domain_exists( $domain, $garren_path );

		// domain_exists() returns null or false if site doesn't exist, or a blog_id if it does.
		if ( empty( $garren_site_id ) || ! is_numeric( $garren_site_id ) ) {
			$garren_site_id = wpmu_create_blog( $domain, $garren_path, 'Garren', 1 );
		}
		// Store site ID if creation was successful (either existing or newly created).
		if ( $garren_site_id && ! is_wp_error( $garren_site_id ) && is_numeric( $garren_site_id ) ) {
			$GLOBALS['iawmlf_test_sites']['garren'] = (int) $garren_site_id;
		}
	},
	1
);

// Start up the WP testing environment.
require getenv( 'WP_PHPUNIT__DIR' ) . '/includes/bootstrap.php';
