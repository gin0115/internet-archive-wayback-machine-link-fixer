<?php

/**
 * Test Site_Lifecycle - Multisite
 *
 * Covers the site created/deleted handlers: links table creation for new
 * sites in separate mode, the available-sites restriction, and full cleanup
 * (table, merge counter, available-sites entry, clone state) on deletion.
 *
 * Real tables are used (temporary-table conversion removed) because the
 * handlers check and drop tables via SHOW TABLES / DROP TABLE.
 *
 * @since 2.0.0
 *
 * @coversDefaultClass \Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Site_Lifecycle
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Tests\Multisite;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Multisite;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Table_Clone_State;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Event\Merge_Duplicate_Links_Batch_Event;

class Test_Site_Lifecycle extends \WP_UnitTestCase {

	/**
	 * Sites created during a test, deleted in tearDown if still present.
	 *
	 * @var array<integer>
	 */
	private $created_sites = array();

	/**
	 * Ensure multisite and real DDL before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure we're in multisite mode.
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite to be enabled.' );
		}

		// The lifecycle handlers create/drop real tables.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->created_sites = array();
		$this->reset_network_state();
	}

	/**
	 * Delete any sites the test created and restore network state.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( $this->created_sites as $site_id ) {
			if ( get_site( $site_id ) ) {
				wp_delete_site( $site_id );
			}
		}

		$this->reset_network_state();

		parent::tearDown();
	}

	/**
	 * Remove the network options these tests manipulate.
	 *
	 * @return void
	 */
	private function reset_network_state(): void {
		delete_network_option( get_current_network_id(), Settings::MULTISITE_LINKS_TABLE_MODE );
		delete_network_option( get_current_network_id(), Settings::MULTISITE_AVAILABLE_SITES );
		Table_Clone_State::delete();
	}

	/**
	 * Create a site through the WordPress API (fires wp_initialize_site).
	 *
	 * @param string $slug Path slug for the new site.
	 *
	 * @return integer The new site ID.
	 */
	private function create_site( string $slug ): int {
		$domain = defined( 'WP_TESTS_DOMAIN' ) ? WP_TESTS_DOMAIN : 'example.org';

		$site_id = wpmu_create_blog( $domain, '/' . $slug . '/', ucfirst( $slug ), get_current_user_id() ? get_current_user_id() : 1 );

		$this->assertIsInt( $site_id, 'Site creation should succeed.' );
		$this->created_sites[] = $site_id;

		return $site_id;
	}

	/**
	 * Does a table really exist?
	 *
	 * @param string $table Table name.
	 *
	 * @return boolean
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * @testdox Creating a site in separate mode should create its links table.
	 *
	 * @return void
	 */
	public function test_new_site_gets_links_table_in_separate_mode(): void {
		Settings::set_multisite_links_table_mode( Multisite::SEPARATE_LINKS_TABLE_MODE );

		$site_id = $this->create_site( 'lifecycle-separate' );

		$this->assertTrue(
			$this->table_exists( Settings::get_subsite_link_table_name( $site_id ) ),
			'A new site in separate mode should get its own links table.'
		);
	}

	/**
	 * @testdox Creating a site in shared mode should not create a per-site links table.
	 *
	 * @return void
	 */
	public function test_new_site_gets_no_table_in_shared_mode(): void {
		Settings::set_multisite_links_table_mode( Multisite::SHARED_LINKS_TABLE_MODE );

		$site_id = $this->create_site( 'lifecycle-shared' );

		$this->assertFalse(
			$this->table_exists( Settings::get_subsite_link_table_name( $site_id ) ),
			'No per-site links table should be created in shared mode.'
		);
	}

	/**
	 * @testdox Creating a site excluded by the available-sites restriction should not create a links table.
	 *
	 * @return void
	 */
	public function test_new_site_outside_available_sites_gets_no_table(): void {
		Settings::set_multisite_links_table_mode( Multisite::SEPARATE_LINKS_TABLE_MODE );

		// Restrict the plugin to the main site only; the new site will not be listed.
		Settings::set_multisite_available_sites( array( 1 ) );

		$site_id = $this->create_site( 'lifecycle-excluded' );

		$this->assertFalse(
			$this->table_exists( Settings::get_subsite_link_table_name( $site_id ) ),
			'A site outside the available-sites restriction should not get a links table.'
		);
	}

	/**
	 * @testdox Deleting a site should drop its links table and remove its merge counter, available-sites entry and clone-state references.
	 *
	 * @return void
	 */
	public function test_deleting_site_cleans_up_every_reference(): void {
		Settings::set_multisite_links_table_mode( Multisite::SEPARATE_LINKS_TABLE_MODE );

		$site_id       = $this->create_site( 'lifecycle-delete' );
		$subsite_table = Settings::get_subsite_link_table_name( $site_id );
		$this->assertTrue( $this->table_exists( $subsite_table ) );

		// Seed every kind of reference to the site.
		update_network_option( get_current_network_id(), Merge_Duplicate_Links_Batch_Event::counter_option( $site_id ), 3 );
		Settings::set_multisite_available_sites( array( 1, $site_id ) );

		$state = Table_Clone_State::from_global( array( 1, $site_id ) );
		$state->add_completed_site( $site_id );
		$state->save();

		wp_delete_site( $site_id );

		$this->assertFalse( $this->table_exists( $subsite_table ), 'The links table should be dropped with the site.' );

		$this->assertFalse(
			get_network_option( get_current_network_id(), Merge_Duplicate_Links_Batch_Event::counter_option( $site_id ), false ),
			'The merge counter should be removed.'
		);

		$this->assertSame(
			array( 1 ),
			Settings::get_multisite_available_sites(),
			'The site should be removed from the available-sites restriction.'
		);

		$saved_state = Table_Clone_State::load();
		$this->assertSame( array( 1 ), $saved_state->get_sites_to_process(), 'The site should be removed from sites to process.' );
		$this->assertSame( array(), $saved_state->get_sites_completed(), 'The site should be removed from completed sites.' );
	}
}
