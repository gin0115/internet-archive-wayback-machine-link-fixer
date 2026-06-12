<?php

/**
 * Test network-aware uninstall - Multisite
 *
 * Covers iawmlf_uninstall() on multisite: the drop-tables opt-in guard, and
 * the full teardown (per-site tables, shared table, per-blog options and
 * network options).
 *
 * Real tables are used; the shared table is recreated in tearDown so the
 * rest of the suite is unaffected.
 *
 * @since 2.0.0
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Tests\Multisite;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Multisite;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Table_Clone_State;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Event\Merge_Duplicate_Links_Batch_Event;

class Test_Uninstall_Multisite extends \WP_UnitTestCase {

	/**
	 * The Garren (sub) site ID.
	 *
	 * @var integer
	 */
	private $garren_site_id;

	/**
	 * Network migration log captured before the test, restored after.
	 *
	 * @var mixed
	 */
	private $saved_migration_log;

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

		// Get test site IDs.
		$garren_site_id = isset( $GLOBALS['iawmlf_test_sites']['garren'] ) ? $GLOBALS['iawmlf_test_sites']['garren'] : null;
		if ( ! $garren_site_id ) {
			$this->markTestSkipped( 'Test site (Garren) is not available.' );
		}
		$this->garren_site_id = (int) $garren_site_id;

		// Uninstall drops real tables.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// The uninstall wipes the network migration log — keep it safe.
		$this->saved_migration_log = get_network_option( get_current_network_id(), Settings::MIGRATIONS_KEY, false );
	}

	/**
	 * Restore tables and options for the rest of the suite.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		// Recreate the shared table if the uninstall dropped it.
		$this->create_links_table( Settings::get_shared_multisite_link_table_name() );

		// Drop any garren table left behind.
		$subsite_table = Settings::get_subsite_link_table_name( $this->garren_site_id );
		$wpdb->query( "DROP TABLE IF EXISTS `$subsite_table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Restore the network migration log and clear test options.
		$network_id = get_current_network_id();
		if ( false !== $this->saved_migration_log ) {
			update_network_option( $network_id, Settings::MIGRATIONS_KEY, $this->saved_migration_log );
		}
		delete_network_option( $network_id, Settings::DROP_TABLES_ON_UNINSTALL_KEY );
		delete_network_option( $network_id, Settings::MULTISITE_LINKS_TABLE_MODE );
		delete_network_option( $network_id, Merge_Duplicate_Links_Batch_Event::counter_option( $this->garren_site_id ) );
		Table_Clone_State::delete();

		// Restore the bootstrap defaults the uninstall removes.
		update_option( Settings::PROCESS_LINKS, true );
		update_option( Settings::POST_ACTIVATION_ONBOARDING_KEY, Settings::ONBOARDING_COMPLETED_OPTION );

		parent::tearDown();
	}

	/**
	 * Create a links table with the Migration_1 schema.
	 *
	 * @param string $table Table name.
	 *
	 * @return void
	 */
	private function create_links_table( string $table ): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		//phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `$table` (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				url longtext NOT NULL,
				archived longtext,
				is_broken tinyint(1) NOT NULL DEFAULT 0,
				checks JSON NOT NULL,
				message longtext,
				redirect_url longtext,
				excluded TINYINT(1) NOT NULL DEFAULT 0,
				archive_process VARCHAR(36) NOT NULL DEFAULT 'new',
				PRIMARY KEY  (id)
			) $charset_collate;" //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
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
	 * @testdox Uninstall should do nothing when wipe-data-on-uninstall is not enabled.
	 *
	 * @return void
	 */
	public function test_uninstall_is_noop_without_drop_optin(): void {
		$network_id = get_current_network_id();
		delete_network_option( $network_id, Settings::DROP_TABLES_ON_UNINSTALL_KEY );

		$shared_table = Settings::get_shared_multisite_link_table_name();
		$this->assertTrue( $this->table_exists( $shared_table ) );

		iawmlf_uninstall();

		$this->assertTrue( $this->table_exists( $shared_table ), 'The shared table must survive without the opt-in.' );
	}

	/**
	 * @testdox A multisite uninstall should drop the shared and per-site tables and delete blog and network options.
	 *
	 * @return void
	 */
	public function test_full_multisite_uninstall(): void {
		global $wpdb;

		$network_id    = get_current_network_id();
		$shared_table  = Settings::get_shared_multisite_link_table_name();
		$subsite_table = Settings::get_subsite_link_table_name( $this->garren_site_id );

		// Opt in to data wiping (network level — that is what the guard reads).
		update_network_option( $network_id, Settings::DROP_TABLES_ON_UNINSTALL_KEY, true );

		// A separate-mode landscape: shared table + a garren table + options.
		Settings::set_multisite_links_table_mode( Multisite::SEPARATE_LINKS_TABLE_MODE );
		$this->create_links_table( $subsite_table );
		update_network_option( $network_id, Merge_Duplicate_Links_Batch_Event::counter_option( $this->garren_site_id ), 2 );

		$state = Table_Clone_State::from_global( array( $this->garren_site_id ) );
		$state->save();

		update_option( Settings::PROCESS_LINKS, true );
		switch_to_blog( $this->garren_site_id );
		update_option( Settings::PROCESS_LINKS, true );
		restore_current_blog();

		iawmlf_uninstall();

		// Tables gone.
		$this->assertFalse( $this->table_exists( $shared_table ), 'Shared table should be dropped.' );
		$this->assertFalse( $this->table_exists( $subsite_table ), 'Per-site table should be dropped.' );

		// Network options gone.
		$this->assertFalse( get_network_option( $network_id, Settings::MULTISITE_LINKS_TABLE_MODE, false ) );
		$this->assertFalse( get_network_option( $network_id, Settings::TABLE_CLONE_STATE, false ) );
		$this->assertFalse( get_network_option( $network_id, Merge_Duplicate_Links_Batch_Event::counter_option( $this->garren_site_id ), false ) );
		$this->assertFalse( get_network_option( $network_id, Settings::DROP_TABLES_ON_UNINSTALL_KEY, false ) );

		// Per-blog options gone on both sites.
		$this->assertFalse( get_option( Settings::PROCESS_LINKS, false ), 'Main site blog options should be cleared.' );
		switch_to_blog( $this->garren_site_id );
		$garren_process_links = get_option( Settings::PROCESS_LINKS, false );
		restore_current_blog();
		$this->assertFalse( $garren_process_links, 'Subsite blog options should be cleared.' );
	}
}
