<?php

/**
 * Test Settings - Multisite
 *
 * @since 1.4.0
 *
 * @coversDefaultClass \Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Tests\Multisite;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Multisite;

class Test_Settings_Multisite extends \WP_UnitTestCase {

	/**
	 * Clear all the options before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure we're in multisite mode.
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite to be enabled.' );
		}
	}

	/**
	 * @testdox It should return the correct table name in multisite mode using base_prefix for shared mode.
	 *
	 * @return void
	 */
	public function test_can_get_link_table_name_in_multisite(): void {
		global $wpdb;

		// Set multisite mode to shared (default).
		Settings::set_multisite_links_table_mode( Multisite::SHARED_LINKS_TABLE_MODE );

		// Get test site IDs.
		$garren_site_id = isset( $GLOBALS['iawmlf_test_sites']['garren'] ) ? $GLOBALS['iawmlf_test_sites']['garren'] : null;
		$dexter_site_id = isset( $GLOBALS['iawmlf_test_sites']['dexter'] ) ? $GLOBALS['iawmlf_test_sites']['dexter'] : null;

		// Skip if test sites are not available.
		if ( ! $garren_site_id || ! $dexter_site_id ) {
			$this->markTestSkipped( 'Test sites (Garren and Dexter) are not available.' );
		}

		// Expected table name for shared mode (uses base_prefix).
		$expected_table_name = $wpdb->base_prefix . Settings::LINK_TABLE;

		// Test from Dexter site (main site, blog_id = 1).
		// This covers both network admin and Dexter site admin contexts since network admin runs on blog_id 1.
		switch_to_blog( $dexter_site_id );
		$dexter_table_name = Settings::get_link_table_name();
		$this->assertEquals( $expected_table_name, $dexter_table_name, 'Dexter site (main site/network admin) should return base_prefix table name' );
		restore_current_blog();

		// Test from Garren site (sub-site, blog_id = 2).
		// This tests the function when called from a sub-site admin or frontend context.
		switch_to_blog( $garren_site_id );
		$garren_table_name = Settings::get_link_table_name();
		$this->assertEquals( $expected_table_name, $garren_table_name, 'Garren site (sub-site) should return base_prefix table name in shared mode' );
		restore_current_blog();

		// Verify both sites return the same table name in shared mode.
		$this->assertEquals( $dexter_table_name, $garren_table_name, 'Both sites should return the same table name in shared mode' );
	}

	/**
	 * @testdox It should return different table names for each site in separate mode using blog prefix.
	 *
	 * @return void
	 */
	public function test_can_get_link_table_name_in_separate_mode(): void {
		global $wpdb;

		// Set multisite mode to separate.
		Settings::set_multisite_links_table_mode( Multisite::SEPARATE_LINKS_TABLE_MODE );

		// Get test site IDs.
		$garren_site_id = isset( $GLOBALS['iawmlf_test_sites']['garren'] ) ? $GLOBALS['iawmlf_test_sites']['garren'] : null;
		$dexter_site_id = isset( $GLOBALS['iawmlf_test_sites']['dexter'] ) ? $GLOBALS['iawmlf_test_sites']['dexter'] : null;

		// Skip if test sites are not available.
		if ( ! $garren_site_id || ! $dexter_site_id ) {
			$this->markTestSkipped( 'Test sites (Garren and Dexter) are not available.' );
		}

		// Test from Dexter site (main site, blog_id = 1).
		switch_to_blog( $dexter_site_id );
		$dexter_table_name = Settings::get_link_table_name();
		$expected_dexter_table_name = $wpdb->get_blog_prefix( $dexter_site_id ) . Settings::LINK_TABLE;
		$this->assertEquals( $expected_dexter_table_name, $dexter_table_name, 'Dexter site should return blog prefix table name in separate mode' );
		restore_current_blog();

		// Test from Garren site (sub-site, blog_id = 2).
		switch_to_blog( $garren_site_id );
		$garren_table_name = Settings::get_link_table_name();
		$expected_garren_table_name = $wpdb->get_blog_prefix( $garren_site_id ) . Settings::LINK_TABLE;
		$this->assertEquals( $expected_garren_table_name, $garren_table_name, 'Garren site should return blog prefix table name in separate mode' );
		restore_current_blog();

		$this->assertNotEquals( $dexter_table_name, $garren_table_name, 'Both sites should return different table names in separate mode' );
	}

	/**
	 * @testdox It should store links from both sites in the shared table when using shared mode.
	 *
	 * @return void
	 */
	public function test_shared_table_contains_links_from_both_sites(): void {
		global $wpdb;

		// Get test site IDs.
		$garren_site_id = isset( $GLOBALS['iawmlf_test_sites']['garren'] ) ? $GLOBALS['iawmlf_test_sites']['garren'] : null;
		$dexter_site_id = isset( $GLOBALS['iawmlf_test_sites']['dexter'] ) ? $GLOBALS['iawmlf_test_sites']['dexter'] : null;

		// Set multisite mode to shared.
		Settings::set_multisite_links_table_mode( Multisite::SHARED_LINKS_TABLE_MODE );
		update_network_option(1, Settings::PROCESS_LINKS, true );
		update_network_option(1, Settings::ALLOWED_POST_TYPES, array( 'post', 'page' ) );

		// Define unique external links (must be different domains from example.org).
		$dexter_link_url = 'https://external-site-1.com/dexter-link';
		$garren_link_url = 'https://external-site-2.com/garren-link';

		// Create post on Dexter site (blog_id 1).
		switch_to_blog( $dexter_site_id );
		$dexter_post_id = \WP_UnitTestCase_Base::factory()->post->create(
			array(
				'post_content' => 'This is a post with a link to <a href="' . $dexter_link_url . '">external site</a>',
			)
		);
		restore_current_blog();

		// Create post on Garren site (blog_id 2).
		switch_to_blog( $garren_site_id );
		$garren_post_id = \WP_UnitTestCase_Base::factory()->post->create(
			array(
				'post_content' => 'This is a post with a link to <a href="' . $garren_link_url . '">external site</a>',
			)
		);
		restore_current_blog();

		// Get the shared table name (should use base_prefix).
		$shared_table_name = $wpdb->base_prefix . Settings::LINK_TABLE;

		// Query the shared table directly to get all links.
		$all_links = $wpdb->get_results( "SELECT * FROM {$shared_table_name} ORDER BY id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, only table name is interpolated.

		// Extract URLs from the results.
		$link_urls = array_map(
			function ( $link ) {
				return $link->url;
			},
			$all_links
		);

		// Verify both links are present in the shared table.
		$this->assertContains( $dexter_link_url, $link_urls, 'Dexter link should be in the shared table' );
		$this->assertContains( $garren_link_url, $link_urls, 'Garren link should be in the shared table' );

		// Verify links can be queried from Dexter site context.
		switch_to_blog( $dexter_site_id );
		$dexter_context_links = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$shared_table_name} WHERE url IN (%s, %s) ORDER BY id", $dexter_link_url, $garren_link_url ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, only table name is interpolated.
		$dexter_context_urls = array_map( function ( $link ) { return $link->url; }, $dexter_context_links );
		$this->assertContains( $dexter_link_url, $dexter_context_urls, 'Dexter link should be queryable from Dexter site context' );
		$this->assertContains( $garren_link_url, $dexter_context_urls, 'Garren link should be queryable from Dexter site context' );
		restore_current_blog();

		// Verify links can be queried from Garren site context.
		switch_to_blog( $garren_site_id );
		$garren_context_links = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$shared_table_name} WHERE url IN (%s, %s) ORDER BY id", $dexter_link_url, $garren_link_url ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, only table name is interpolated.
		$garren_context_urls = array_map( function ( $link ) { return $link->url; }, $garren_context_links );
		$this->assertContains( $dexter_link_url, $garren_context_urls, 'Dexter link should be queryable from Garren site context' );
		$this->assertContains( $garren_link_url, $garren_context_urls, 'Garren link should be queryable from Garren site context' );
		restore_current_blog();
	}

	/**
	 * @testdox It should not register save_post hooks on disabled sites.
	 *
	 * @return void
	 */
	public function test_save_post_hook_not_registered_on_disabled_site(): void {
		// Set multisite mode to shared.
		Settings::set_multisite_links_table_mode( Multisite::SHARED_LINKS_TABLE_MODE );

		$clear_hooks = function() {
			remove_all_filters( 'save_post' );
		};

		$clear_hooks();

		// Get test site IDs.
		$garren_site_id = isset( $GLOBALS['iawmlf_test_sites']['garren'] ) ? $GLOBALS['iawmlf_test_sites']['garren'] : null;
		$dexter_site_id = isset( $GLOBALS['iawmlf_test_sites']['dexter'] ) ? $GLOBALS['iawmlf_test_sites']['dexter'] : null;

		// Skip if test sites are not available.
		if ( ! $garren_site_id || ! $dexter_site_id ) {
			$this->markTestSkipped( 'Test sites (Garren and Dexter) are not available.' );
		}

		// Set available sites to only include Dexter (site 1) - disable Garren (site 2).
		Settings::set_multisite_available_sites( array( $dexter_site_id ) );

		// Test Dexter site (ENABLED) - hooks should be registered.
		switch_to_blog( $dexter_site_id );

		// Reset plugin instance to ensure fresh initialization.
		$plugin = iawmlf_get_plugin_instance();
		$plugin->integrations = null;

		// Manually trigger plugin initialization.
		$plugin->maybe_initialize();

		// Get all save_post callbacks.
		$callbacks = $this->get_save_post_callbacks();

		// Check if our hook is registered by looking for the WP_Post_Controller class.
		$hook_found = false;
		foreach ( $callbacks as $callback_string ) {
			if ( strpos( $callback_string, 'WP_Post_Controller::on_save_post_process_post_links' ) !== false
				|| strpos( $callback_string, 'Internet_Archive\\Wayback_Machine_Link_Fixer\\WP_Post\\WP_Post_Controller::on_save_post_process_post_links' ) !== false ) {
				$hook_found = true;
				break;
			}
		}

		// Verify the hook IS registered (Dexter is enabled).
		$this->assertTrue( $hook_found, 'save_post hook should be registered on Dexter site (enabled)' );

		restore_current_blog();
		$clear_hooks();

		// Test Garren site (DISABLED) - hooks should NOT be registered.
		switch_to_blog( $garren_site_id );

		// Reset plugin instance to ensure fresh initialization.
		$plugin = iawmlf_get_plugin_instance();
		$plugin->integrations = null;

		// Manually trigger plugin initialization.
		$plugin->maybe_initialize();

		// Get all save_post callbacks.
		$callbacks = $this->get_save_post_callbacks();

		// Check if our hook is registered by looking for the WP_Post_Controller class.
		$hook_found = false;
		foreach ( $callbacks as $callback_string ) {
			if ( strpos( $callback_string, 'WP_Post_Controller::on_save_post_process_post_links' ) !== false
				|| strpos( $callback_string, 'Internet_Archive\\Wayback_Machine_Link_Fixer\\WP_Post\\WP_Post_Controller::on_save_post_process_post_links' ) !== false ) {
				$hook_found = true;
				break;
			}
		}

		// Verify the hook is NOT registered (Garren is disabled).
		$this->assertFalse( $hook_found, 'save_post hook should NOT be registered on Garren site (disabled)' );

		restore_current_blog();
	}

	/**
	 * @testdox It should prioritize network options over site options in shared mode.
	 *
	 * @return void
	 */
	public function test_shared_mode_settings_shared_across_sites(): void {
		// Get test site IDs.
		$garren_site_id = isset( $GLOBALS['iawmlf_test_sites']['garren'] ) ? $GLOBALS['iawmlf_test_sites']['garren'] : null;
		$dexter_site_id = isset( $GLOBALS['iawmlf_test_sites']['dexter'] ) ? $GLOBALS['iawmlf_test_sites']['dexter'] : null;

		// Skip if test sites are not available.
		if ( ! $garren_site_id || ! $dexter_site_id ) {
			$this->markTestSkipped( 'Test sites (Garren and Dexter) are not available.' );
		}

		// Set multisite mode to shared.
		Settings::set_multisite_links_table_mode( Multisite::SHARED_LINKS_TABLE_MODE );

		// Set network option to TRUE.
		update_network_option( get_current_network_id(), Settings::PROCESS_LINKS, true );

		// Set site option to FALSE on both sites (opposite of network).
		switch_to_blog( $dexter_site_id );
		update_option( Settings::PROCESS_LINKS, false );
		// In shared mode, network option should take precedence.
		$this->assertTrue( Settings::is_link_processing_enabled(), 'Dexter should return network value (TRUE) in shared mode, not site value (FALSE)' );
		restore_current_blog();

		switch_to_blog( $garren_site_id );
		update_option( Settings::PROCESS_LINKS, false );
		// In shared mode, network option should take precedence.
		$this->assertTrue( Settings::is_link_processing_enabled(), 'Garren should return network value (TRUE) in shared mode, not site value (FALSE)' );
		restore_current_blog();

		// Test reverse: network FALSE, site TRUE - network should still win.
		update_network_option( get_current_network_id(), Settings::PROCESS_LINKS, false );

		switch_to_blog( $dexter_site_id );
		update_option( Settings::PROCESS_LINKS, true );
		$this->assertFalse( Settings::is_link_processing_enabled(), 'Dexter should return network value (FALSE) in shared mode, not site value (TRUE)' );
		restore_current_blog();

		switch_to_blog( $garren_site_id );
		update_option( Settings::PROCESS_LINKS, true );
		$this->assertFalse( Settings::is_link_processing_enabled(), 'Garren should return network value (FALSE) in shared mode, not site value (TRUE)' );
		restore_current_blog();
	}

	/**
	 * @testdox It should prioritize site options over network options in separate mode.
	 *
	 * @return void
	 */
	public function test_separate_mode_settings_isolated_per_site(): void {
		// Get test site IDs.
		$garren_site_id = isset( $GLOBALS['iawmlf_test_sites']['garren'] ) ? $GLOBALS['iawmlf_test_sites']['garren'] : null;
		$dexter_site_id = isset( $GLOBALS['iawmlf_test_sites']['dexter'] ) ? $GLOBALS['iawmlf_test_sites']['dexter'] : null;

		// Skip if test sites are not available.
		if ( ! $garren_site_id || ! $dexter_site_id ) {
			$this->markTestSkipped( 'Test sites (Garren and Dexter) are not available.' );
		}

		// Set multisite mode to separate.
		Settings::set_multisite_links_table_mode( Multisite::SEPARATE_LINKS_TABLE_MODE );

		// Set network option to TRUE.
		update_network_option( get_current_network_id(), Settings::PROCESS_LINKS, true );

		// Set site option to FALSE on Dexter (opposite of network).
		switch_to_blog( $dexter_site_id );
		update_option( Settings::PROCESS_LINKS, false );
		// In separate mode, site option should take precedence.
		$this->assertFalse( Settings::is_link_processing_enabled(), 'Dexter should return site value (FALSE) in separate mode, not network value (TRUE)' );
		restore_current_blog();

		// Set site option to TRUE on Garren (same as network, but should still use site).
		switch_to_blog( $garren_site_id );
		update_option( Settings::PROCESS_LINKS, true );
		// In separate mode, site option should take precedence.
		$this->assertTrue( Settings::is_link_processing_enabled(), 'Garren should return site value (TRUE) in separate mode' );
		restore_current_blog();

		// Test reverse: network FALSE, site TRUE - site should still win.
		update_network_option( get_current_network_id(), Settings::PROCESS_LINKS, false );

		switch_to_blog( $dexter_site_id );
		update_option( Settings::PROCESS_LINKS, true );
		$this->assertTrue( Settings::is_link_processing_enabled(), 'Dexter should return site value (TRUE) in separate mode, not network value (FALSE)' );
		restore_current_blog();

		switch_to_blog( $garren_site_id );
		update_option( Settings::PROCESS_LINKS, false );
		$this->assertFalse( Settings::is_link_processing_enabled(), 'Garren should return site value (FALSE) in separate mode, not network value (FALSE)' );
		restore_current_blog();
	}

	/**
	 * @testdox It should prioritize network options for multiple settings in shared mode.
	 *
	 * @return void
	 */
	public function test_multiple_settings_shared_mode(): void {
		// Get test site IDs.
		$garren_site_id = isset( $GLOBALS['iawmlf_test_sites']['garren'] ) ? $GLOBALS['iawmlf_test_sites']['garren'] : null;
		$dexter_site_id = isset( $GLOBALS['iawmlf_test_sites']['dexter'] ) ? $GLOBALS['iawmlf_test_sites']['dexter'] : null;

		// Skip if test sites are not available.
		if ( ! $garren_site_id || ! $dexter_site_id ) {
			$this->markTestSkipped( 'Test sites (Garren and Dexter) are not available.' );
		}

		// Set multisite mode to shared.
		Settings::set_multisite_links_table_mode( Multisite::SHARED_LINKS_TABLE_MODE );

		// Set network options.
		update_network_option( get_current_network_id(), Settings::PROCESS_LINKS, true );
		update_network_option( get_current_network_id(), Settings::ALLOWED_POST_TYPES, array( 'post', 'page', 'custom' ) );
		update_network_option( get_current_network_id(), Settings::FIXER_OPTION, Settings::FIXER_OPTION_DO_NOTHING );
		update_network_option( get_current_network_id(), Settings::LINK_EXCLUSIONS, array( 'https://network.com' ) );

		// Set different site options on both sites (opposite of network).
		switch_to_blog( $dexter_site_id );
		update_option( Settings::PROCESS_LINKS, false );
		update_option( Settings::ALLOWED_POST_TYPES, array( 'dexter-only' ) );
		update_option( Settings::FIXER_OPTION, Settings::FIXER_OPTION_REPLACE_LINK );
		update_option( Settings::LINK_EXCLUSIONS, array( 'https://dexter.com' ) );
		restore_current_blog();

		switch_to_blog( $garren_site_id );
		update_option( Settings::PROCESS_LINKS, false );
		update_option( Settings::ALLOWED_POST_TYPES, array( 'garren-only' ) );
		update_option( Settings::FIXER_OPTION, Settings::FIXER_OPTION_REPLACE_LINK );
		update_option( Settings::LINK_EXCLUSIONS, array( 'https://garren.com' ) );
		restore_current_blog();

		// Verify all settings return network values from Dexter site (network takes precedence).
		switch_to_blog( $dexter_site_id );
		$this->assertTrue( Settings::is_link_processing_enabled(), 'Dexter should return network value (TRUE) in shared mode' );
		$this->assertEquals( array( 'post', 'page', 'custom' ), Settings::get_allowed_post_types(), 'Dexter should return network ALLOWED_POST_TYPES in shared mode' );
		$this->assertEquals( Settings::FIXER_OPTION_DO_NOTHING, Settings::get_fixer_option(), 'Dexter should return network FIXER_OPTION in shared mode' );
		$this->assertEquals( array( 'https://network.com' ), Settings::get_link_exclusions(), 'Dexter should return network LINK_EXCLUSIONS in shared mode' );
		restore_current_blog();

		// Verify all settings return network values from Garren site (network takes precedence).
		switch_to_blog( $garren_site_id );
		$this->assertTrue( Settings::is_link_processing_enabled(), 'Garren should return network value (TRUE) in shared mode' );
		$this->assertEquals( array( 'post', 'page', 'custom' ), Settings::get_allowed_post_types(), 'Garren should return network ALLOWED_POST_TYPES in shared mode' );
		$this->assertEquals( Settings::FIXER_OPTION_DO_NOTHING, Settings::get_fixer_option(), 'Garren should return network FIXER_OPTION in shared mode' );
		$this->assertEquals( array( 'https://network.com' ), Settings::get_link_exclusions(), 'Garren should return network LINK_EXCLUSIONS in shared mode' );
		restore_current_blog();
	}

	/**
	 * @testdox It should prioritize site options for multiple settings in separate mode.
	 *
	 * @return void
	 */
	public function test_multiple_settings_separate_mode(): void {
		// Get test site IDs.
		$garren_site_id = isset( $GLOBALS['iawmlf_test_sites']['garren'] ) ? $GLOBALS['iawmlf_test_sites']['garren'] : null;
		$dexter_site_id = isset( $GLOBALS['iawmlf_test_sites']['dexter'] ) ? $GLOBALS['iawmlf_test_sites']['dexter'] : null;

		// Skip if test sites are not available.
		if ( ! $garren_site_id || ! $dexter_site_id ) {
			$this->markTestSkipped( 'Test sites (Garren and Dexter) are not available.' );
		}

		// Set multisite mode to separate.
		Settings::set_multisite_links_table_mode( Multisite::SEPARATE_LINKS_TABLE_MODE );

		// Set network options (these should be ignored in separate mode).
		update_network_option( get_current_network_id(), Settings::PROCESS_LINKS, true );
		update_network_option( get_current_network_id(), Settings::ALLOWED_POST_TYPES, array( 'network', 'values' ) );
		update_network_option( get_current_network_id(), Settings::FIXER_OPTION, Settings::FIXER_OPTION_DO_NOTHING );
		update_network_option( get_current_network_id(), Settings::LINK_EXCLUSIONS, array( 'https://network.com' ) );

		// Set different site options on Dexter site (opposite of network).
		switch_to_blog( $dexter_site_id );
		update_option( Settings::PROCESS_LINKS, false );
		update_option( Settings::ALLOWED_POST_TYPES, array( 'post', 'page' ) );
		update_option( Settings::FIXER_OPTION, Settings::FIXER_OPTION_REPLACE_LINK );
		update_option( Settings::LINK_EXCLUSIONS, array( 'https://dexter.com' ) );
		restore_current_blog();

		// Set different site options on Garren site (different from both network and Dexter).
		switch_to_blog( $garren_site_id );
		update_option( Settings::PROCESS_LINKS, false );
		update_option( Settings::ALLOWED_POST_TYPES, array( 'custom' ) );
		update_option( Settings::FIXER_OPTION, Settings::FIXER_OPTION_REPLACE_LINK );
		update_option( Settings::LINK_EXCLUSIONS, array( 'https://garren.com' ) );
		restore_current_blog();

		// Verify Dexter site returns its own site values (site takes precedence over network).
		switch_to_blog( $dexter_site_id );
		$this->assertFalse( Settings::is_link_processing_enabled(), 'Dexter should return site value (FALSE) in separate mode, not network value (TRUE)' );
		$this->assertEquals( array( 'post', 'page' ), Settings::get_allowed_post_types(), 'Dexter should return site ALLOWED_POST_TYPES in separate mode' );
		$this->assertEquals( Settings::FIXER_OPTION_REPLACE_LINK, Settings::get_fixer_option(), 'Dexter should return site FIXER_OPTION in separate mode' );
		$this->assertEquals( array( 'https://dexter.com' ), Settings::get_link_exclusions(), 'Dexter should return site LINK_EXCLUSIONS in separate mode' );
		restore_current_blog();

		// Verify Garren site returns its own site values (site takes precedence over network).
		switch_to_blog( $garren_site_id );
		$this->assertFalse( Settings::is_link_processing_enabled(), 'Garren should return site value (FALSE) in separate mode, not network value (TRUE)' );
		$this->assertEquals( array( 'custom' ), Settings::get_allowed_post_types(), 'Garren should return site ALLOWED_POST_TYPES in separate mode' );
		$this->assertEquals( Settings::FIXER_OPTION_REPLACE_LINK, Settings::get_fixer_option(), 'Garren should return site FIXER_OPTION in separate mode' );
		$this->assertEquals( array( 'https://garren.com' ), Settings::get_link_exclusions(), 'Garren should return site LINK_EXCLUSIONS in separate mode' );
		restore_current_blog();
	}

	/**
	 * Gets the save post callbacks as simple strings.
	 *
	 * @return string[]
	 */
	private function get_save_post_callbacks(): array {
		$callback_strings = array();

		if ( ! isset( $GLOBALS['wp_filter']['save_post'] ) ) {
			return $callback_strings;
		}

		$hook = $GLOBALS['wp_filter']['save_post'];

		$format_callback = function( $function ) {
			if ( is_array( $function ) && count( $function ) === 2 ) {
				if ( is_object( $function[0] ) ) {
					return get_class( $function[0] ) . '::' . $function[1];
				} elseif ( is_string( $function[0] ) ) {
					return $function[0] . '::' . $function[1];
				}
			} elseif ( is_string( $function ) ) {
				return $function;
			}
			return null;
		};

		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$formatted = $format_callback( $callback['function'] );
				if ( $formatted ) {
					$callback_strings[] = $formatted;
				}
			}
		}

		return $callback_strings;
	}
}
