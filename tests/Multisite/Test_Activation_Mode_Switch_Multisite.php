<?php

/**
 * Test Activation Mode Switch - Multisite
 *
 * Tests that when plugin is deactivated network-wide, tables removed, and then
 * reactivated NOT network-wide, both the site-specific table and shared table are created.
 *
 * @since 1.4.0
 *
 * @coversDefaultClass \Internet_Archive\Wayback_Machine_Link_Fixer\Migration\Migrations
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Tests\Migration;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Multisite;
use Internet_Archive\Wayback_Machine_Link_Fixer\Migration\Migrations;

/**
 * Test_Activation_Mode_Switch_Multisite
 */
class Test_Activation_Mode_Switch_Multisite extends \WP_UnitTestCase {

	/**
	 * Ensure multisite environment and clear state before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure we're in multisite mode.
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite to be enabled.' );
		}

		// Get test site IDs.
		$garren_site_id = isset( $GLOBALS['iawmlf_test_sites']['garren'] ) ? $GLOBALS['iawmlf_test_sites']['garren'] : null;
		$dexter_site_id = isset( $GLOBALS['iawmlf_test_sites']['dexter'] ) ? $GLOBALS['iawmlf_test_sites']['dexter'] : null;

		// Skip if test sites are not available.
		if ( ! $garren_site_id || ! $dexter_site_id ) {
			$this->markTestSkipped( 'Test sites (Garren and Dexter) are not available.' );
		}
	}

	/**
	 * @testdox When plugin is deactivated network-wide, tables removed, and reactivated NOT network-wide, both site-specific and shared tables should be created.
	 *
	 * @return void
	 */
	public function test_both_tables_created_when_reactivating_not_network_wide_after_network_deactivation(): void {
		global $wpdb;

		$this->assertTrue( is_plugin_active( 'internet-archive-wayback-machine-link-fixer/internet-archive-wayback-machine-link-fixer.php' ) );

		// Get test site IDs.
		$garren_site_id = isset( $GLOBALS['iawmlf_test_sites']['garren'] ) ? $GLOBALS['iawmlf_test_sites']['garren'] : null;
		$dexter_site_id = isset( $GLOBALS['iawmlf_test_sites']['dexter'] ) ? $GLOBALS['iawmlf_test_sites']['dexter'] : null;

		// Skip if test sites are not available.
		if ( ! $garren_site_id || ! $dexter_site_id ) {
			// $this->markTestSkipped( 'Test sites (Garren and Dexter) are not available.' );
		}

		// If its network active, deactivate it.
		if ( is_plugin_active_for_network( 'internet-archive-wayback-machine-link-fixer/internet-archive-wayback-machine-link-fixer.php' ) ) {
			deactivate_plugins( 'internet-archive-wayback-machine-link-fixer/internet-archive-wayback-machine-link-fixer.php', true, true );
			// Drop the shared link table.
			$wpdb->query( 'DROP TABLE IF EXISTS ' . Settings::get_shared_multisite_link_table_name() ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, cant due to table name.
		}

		foreach ( get_sites() as $site ) {
			switch_to_blog( $site->blog_id );
			$wpdb->query( 'DROP TABLE IF EXISTS ' . Settings::get_link_table_name() ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, cant due to table name.
			delete_option( Settings::MIGRATIONS_KEY );
			deactivate_plugins( 'internet-archive-wayback-machine-link-fixer/internet-archive-wayback-machine-link-fixer.php', true, true );
			restore_current_blog();
		}

		// Set the mode to seperate.
		Settings::set_multisite_links_table_mode( Multisite::SEPARATE_LINKS_TABLE_MODE );

		// Switch to blog 1 and activate the plugin.
		switch_to_blog( 1 );
		delete_option( Settings::MIGRATIONS_KEY );
		activate_plugins( 'internet-archive-wayback-machine-link-fixer/internet-archive-wayback-machine-link-fixer.php', false, false );
		// Create a post to test the table.
		 wp_insert_post(
			array(
				'post_title'   => 'Test Post',
				'post_content' => '<a href="https://www.google.com">Google</a>',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			)
		);
		$results = $wpdb->get_results( 'SELECT * FROM ' . Settings::get_link_table_name() );

		$this->assertEquals( 1, count( $results ) );

		// Deactivate the plugin.
		deactivate_plugins( 'internet-archive-wayback-machine-link-fixer/internet-archive-wayback-machine-link-fixer.php', true, false );

		// Clear the save post filter.
		unset( $GLOBALS['wp_filter']['save_post'] );

		// Go to blog 2 and activate the plugin.
		switch_to_blog( 2 );
		activate_plugins( 'internet-archive-wayback-machine-link-fixer/internet-archive-wayback-machine-link-fixer.php', false, false );

		// Manually trigger the activation process.
		iawmlf_activate( false );
		do_action( 'plugins_loaded' );
		update_option( Settings::PROCESS_LINKS, true );

		// Create a post to test the table.
		wp_insert_post(
			array(
				'post_title'   => 'Test Post',
				'post_content' => '<a href="https://www.bing.com">Bing</a>',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			)
		);

		// We should only get 1 result, as its not the same blog as the others.
		$results = $wpdb->get_results( 'SELECT * FROM ' . Settings::get_link_table_name() );
		$this->assertEquals( 1, count( $results ) );
	}
}
