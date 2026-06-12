<?php

/**
 * Test Table_Manager - Multisite
 *
 * Covers both clone directions (shared to separate, separate to shared),
 * the reset/truncate options and the operation log.
 *
 * These tests use REAL tables: the WP test framework's temporary-table
 * conversion is removed per test because Table_Manager existence checks
 * (SHOW TABLES LIKE) cannot see temporary tables. All tables and options
 * touched are cleaned up in tearDown.
 *
 * @since 2.0.0
 *
 * @coversDefaultClass \Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Table_Manager
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Tests\Multisite;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Table_Manager;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Event\Merge_Duplicate_Links_Batch_Event;

class Test_Table_Manager extends \WP_UnitTestCase {

	/**
	 * The Garren (sub) site ID.
	 *
	 * @var integer
	 */
	private $garren_site_id;

	/**
	 * Ensure multisite, real DDL and clean tables before each test.
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

		// Table_Manager checks table existence with SHOW TABLES LIKE, which cannot
		// see the TEMPORARY tables the test framework normally substitutes in.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->clean_tables_and_options();
	}

	/**
	 * Drop/clean everything the tests create.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		// Recover from the known blog-context leak in clone_to_separate_tables()
		// (plan section 4.2) so later assertions/tests run on the main site.
		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		$this->clean_tables_and_options();

		parent::tearDown();
	}

	/**
	 * Shared cleanup: truncate the shared table, drop the subsite table and
	 * remove the options the production code writes.
	 *
	 * @return void
	 */
	private function clean_tables_and_options(): void {
		global $wpdb;

		$shared_table  = Settings::get_shared_multisite_link_table_name();
		$subsite_table = Settings::get_subsite_link_table_name( $this->garren_site_id );

		$wpdb->query( "TRUNCATE TABLE `$shared_table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `$subsite_table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Per-blog migration logs written by multisite_up()/multisite_down().
		delete_option( Settings::MIGRATIONS_KEY );
		switch_to_blog( $this->garren_site_id );
		delete_option( Settings::MIGRATIONS_KEY );
		restore_current_blog();

		// Merge counter + queued merge actions.
		delete_network_option( 0, Merge_Duplicate_Links_Batch_Event::counter_option( $this->garren_site_id ) );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Merge_Duplicate_Links_Batch_Event::HANDLE );
		}
	}

	/**
	 * Insert a link row into the given table.
	 *
	 * @param string $table Table name.
	 * @param array  $row   Partial row, merged over defaults.
	 *
	 * @return void
	 */
	private function seed_link( string $table, array $row = array() ): void {
		global $wpdb;

		$wpdb->insert(
			$table,
			array_merge(
				array(
					'url'             => 'https://example.com/' . wp_generate_uuid4(),
					'archived'        => null,
					'is_broken'       => 0,
					'checks'          => '[]',
					'message'         => '',
					'redirect_url'    => '',
					'excluded'        => 0,
					'archive_process' => 'new',
				),
				$row
			)
		);

		$this->assertSame( '', $wpdb->last_error, 'Seeding a link row should not error.' );
	}

	/**
	 * Count rows in a table.
	 *
	 * @param string $table Table name.
	 *
	 * @return integer
	 */
	private function count_rows( string $table ): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Does a table really exist (not as a temporary table)?
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
	 * @testdox Cloning to a separate table should create the subsite table and copy every shared row into it, leaving the shared table untouched.
	 *
	 * @return void
	 */
	public function test_clone_to_separate_tables_copies_all_rows(): void {
		$shared_table  = Settings::get_shared_multisite_link_table_name();
		$subsite_table = Settings::get_subsite_link_table_name( $this->garren_site_id );

		$this->seed_link( $shared_table, array( 'url' => 'https://example.com/one' ) );
		$this->seed_link( $shared_table, array( 'url' => 'https://example.com/two' ) );
		$this->seed_link( $shared_table, array( 'url' => 'https://example.com/three' ) );

		$manager = new Table_Manager();
		$manager->clone_to_separate_tables( $this->garren_site_id, false, false );

		// Known leak: the call leaves us switched to the subsite (plan 4.2).
		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		$this->assertTrue( $this->table_exists( $subsite_table ), 'Subsite table should have been created.' );
		$this->assertSame( 3, $this->count_rows( $subsite_table ), 'All shared rows should be cloned to the subsite table.' );
		$this->assertSame( 3, $this->count_rows( $shared_table ), 'Shared table should be untouched without reset_main_table.' );

		// The log should contain a success entry for the clone.
		$statuses = wp_list_pluck( $manager->get_log(), 'status' );
		$this->assertContains( 'success', $statuses );
		$this->assertNotContains( 'error', $statuses );
	}

	/**
	 * @testdox Cloning with reset checks enabled should blank checks and is_broken on the cloned rows but not on the shared source rows.
	 *
	 * @return void
	 */
	public function test_clone_to_separate_tables_can_reset_checks(): void {
		global $wpdb;

		$shared_table  = Settings::get_shared_multisite_link_table_name();
		$subsite_table = Settings::get_subsite_link_table_name( $this->garren_site_id );

		$this->seed_link(
			$shared_table,
			array(
				'url'       => 'https://example.com/broken',
				'is_broken' => 1,
				'checks'    => '["2026-01-01"]',
			)
		);

		$manager = new Table_Manager();
		$manager->clone_to_separate_tables( $this->garren_site_id, true, false );

		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		$cloned = $wpdb->get_row( "SELECT is_broken, checks FROM `$subsite_table`", ARRAY_A ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( '0', (string) $cloned['is_broken'], 'Cloned row should have is_broken reset.' );
		$this->assertSame( '[]', $cloned['checks'], 'Cloned row should have checks reset.' );

		$source = $wpdb->get_row( "SELECT is_broken, checks FROM `$shared_table`", ARRAY_A ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( '1', (string) $source['is_broken'], 'Shared source row should keep is_broken.' );
		$this->assertSame( '["2026-01-01"]', $source['checks'], 'Shared source row should keep its checks.' );
	}

	/**
	 * @testdox Cloning with reset main table enabled should truncate the shared table after copying.
	 *
	 * @return void
	 */
	public function test_clone_to_separate_tables_can_reset_main_table(): void {
		$shared_table  = Settings::get_shared_multisite_link_table_name();
		$subsite_table = Settings::get_subsite_link_table_name( $this->garren_site_id );

		$this->seed_link( $shared_table, array( 'url' => 'https://example.com/one' ) );
		$this->seed_link( $shared_table, array( 'url' => 'https://example.com/two' ) );

		$manager = new Table_Manager();
		$manager->clone_to_separate_tables( $this->garren_site_id, false, true );

		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		$this->assertSame( 2, $this->count_rows( $subsite_table ), 'Rows should be cloned before the source reset.' );
		$this->assertSame( 0, $this->count_rows( $shared_table ), 'Shared table should be truncated with reset_main_table.' );
	}

	/**
	 * @testdox Cloning should self-heal when the site's migration log claims the migrations ran but the table is missing.
	 *
	 * @return void
	 */
	public function test_clone_to_separate_tables_self_heals_stale_migration_log(): void {
		$shared_table  = Settings::get_shared_multisite_link_table_name();
		$subsite_table = Settings::get_subsite_link_table_name( $this->garren_site_id );

		// Poison the subsite's migration log: claims everything ran, but no
		// table exists (the state the old wrong-context multisite_down left).
		switch_to_blog( $this->garren_site_id );
		Settings::update_migrations( \Internet_Archive\Wayback_Machine_Link_Fixer\Migration\Migrations::$migrations, true );
		restore_current_blog();
		$this->assertFalse( $this->table_exists( $subsite_table ) );

		$this->seed_link( $shared_table, array( 'url' => 'https://example.com/heal-me' ) );

		$manager = new Table_Manager();
		$manager->clone_to_separate_tables( $this->garren_site_id, false, false );

		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		$this->assertTrue( $this->table_exists( $subsite_table ), 'The table should be force-created despite the stale log.' );
		$this->assertSame( 1, $this->count_rows( $subsite_table ), 'The shared rows should be cloned after self-healing.' );
	}

	/**
	 * @testdox Cloning to a site that does not exist should throw and add an error log entry.
	 *
	 * @return void
	 */
	public function test_clone_to_separate_tables_rejects_unknown_site(): void {
		$manager = new Table_Manager();

		try {
			$manager->clone_to_separate_tables( 99999, false, false );
			$this->fail( 'An exception should have been thrown for an unknown site.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( '99999', $e->getMessage() );
		}

		$statuses = wp_list_pluck( $manager->get_log(), 'status' );
		$this->assertContains( 'error', $statuses );
	}

	/**
	 * @testdox Migrating to the shared table should insert only non-duplicate rows and drop the subsite table when there are no duplicates.
	 *
	 * @return void
	 */
	public function test_migrate_to_shared_table_without_duplicates_drops_subsite_table(): void {
		global $wpdb;

		$shared_table  = Settings::get_shared_multisite_link_table_name();
		$subsite_table = Settings::get_subsite_link_table_name( $this->garren_site_id );

		// Existing shared content.
		$this->seed_link( $shared_table, array( 'url' => 'https://example.com/shared-only' ) );

		// Subsite table with two new URLs.
		$this->create_subsite_table( $subsite_table );
		$this->seed_link( $subsite_table, array( 'url' => 'https://example.com/sub-one' ) );
		$this->seed_link( $subsite_table, array( 'url' => 'https://example.com/sub-two' ) );

		$manager = new Table_Manager();
		$manager->migrate_to_shared_table( $this->garren_site_id );

		$this->assertSame( 3, $this->count_rows( $shared_table ), 'Both new subsite rows should land in the shared table.' );
		$this->assertFalse( $this->table_exists( $subsite_table ), 'Subsite table should be dropped when no duplicates remain.' );

		$urls = $wpdb->get_col( "SELECT url FROM `$shared_table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertContains( 'https://example.com/sub-one', $urls );
		$this->assertContains( 'https://example.com/sub-two', $urls );
	}

	/**
	 * @testdox Migrating with duplicate URLs should not double-insert them, should queue merge tasks and should leave the subsite table in place.
	 *
	 * @return void
	 */
	public function test_migrate_to_shared_table_queues_duplicates(): void {
		$shared_table  = Settings::get_shared_multisite_link_table_name();
		$subsite_table = Settings::get_subsite_link_table_name( $this->garren_site_id );

		// Same URL on both sides = a duplicate; one new URL.
		$this->seed_link( $shared_table, array( 'url' => 'https://example.com/duplicate' ) );

		$this->create_subsite_table( $subsite_table );
		$this->seed_link( $subsite_table, array( 'url' => 'https://example.com/duplicate' ) );
		$this->seed_link( $subsite_table, array( 'url' => 'https://example.com/fresh' ) );

		$manager = new Table_Manager();
		$manager->migrate_to_shared_table( $this->garren_site_id );

		// Only the fresh URL is inserted; the duplicate is not double-inserted.
		$this->assertSame( 2, $this->count_rows( $shared_table ) );

		// One chunk of duplicates (< 50 rows) = counter of 1.
		$counter = get_network_option( 0, Merge_Duplicate_Links_Batch_Event::counter_option( $this->garren_site_id ) );
		$this->assertSame( 1, (int) $counter, 'One merge chunk should be pending.' );

		// A merge action should be queued and the subsite table must survive
		// until the merge tasks complete.
		$this->assertTrue(
			as_has_scheduled_action( Merge_Duplicate_Links_Batch_Event::HANDLE ),
			'A merge batch action should be queued.'
		);
		$this->assertTrue( $this->table_exists( $subsite_table ), 'Subsite table must not be dropped while merges are pending.' );
	}

	/**
	 * @testdox Migrating a site whose subsite table is missing should be a logged no-op rather than an error.
	 *
	 * @return void
	 */
	public function test_migrate_to_shared_table_skips_missing_subsite_table(): void {
		$shared_table = Settings::get_shared_multisite_link_table_name();
		$this->seed_link( $shared_table, array( 'url' => 'https://example.com/shared-only' ) );

		$manager = new Table_Manager();
		$manager->migrate_to_shared_table( $this->garren_site_id );

		$this->assertSame( 1, $this->count_rows( $shared_table ), 'Shared table should be unchanged.' );

		$messages = wp_list_pluck( $manager->get_log(), 'message' );
		$this->assertNotEmpty( $messages );
		$this->assertStringContainsString( 'missing', strtolower( implode( ' ', $messages ) ) );
	}

	/**
	 * @testdox Migrating an unknown site should throw and add an error log entry.
	 *
	 * @return void
	 */
	public function test_migrate_to_shared_table_rejects_unknown_site(): void {
		$manager = new Table_Manager();

		try {
			$manager->migrate_to_shared_table( 99999 );
			$this->fail( 'An exception should have been thrown for an unknown site.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( '99999', $e->getMessage() );
		}

		$statuses = wp_list_pluck( $manager->get_log(), 'status' );
		$this->assertContains( 'error', $statuses );
	}

	/**
	 * @testdox Cloning the main site to "separate" must be a no-op that keeps the shared table and its data intact (their names collide).
	 *
	 * @return void
	 */
	public function test_clone_to_separate_tables_main_site_is_noop(): void {
		$shared_table = Settings::get_shared_multisite_link_table_name();

		$this->seed_link(
			$shared_table,
			array(
				'url'       => 'https://example.com/keep-me',
				'is_broken' => 1,
				'checks'    => '["2026-01-01"]',
			)
		);

		$manager = new Table_Manager();
		// Site 1's blog prefix is the base prefix — same table name as shared.
		$manager->clone_to_separate_tables( 1, true, false );

		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		global $wpdb;
		$row = $wpdb->get_row( "SELECT is_broken, checks FROM `$shared_table`", ARRAY_A ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertNotNull( $row, 'The shared table data must survive a main-site clone.' );
		$this->assertSame( '1', (string) $row['is_broken'], 'Main-site clone must not reset checks on the shared table.' );

		$statuses = wp_list_pluck( $manager->get_log(), 'status' );
		$this->assertNotContains( 'error', $statuses );
	}

	/**
	 * @testdox Migrating the main site to "shared" must be a no-op that never drops the shared table (their names collide).
	 *
	 * @return void
	 */
	public function test_migrate_to_shared_table_main_site_is_noop(): void {
		$shared_table = Settings::get_shared_multisite_link_table_name();

		$this->seed_link( $shared_table, array( 'url' => 'https://example.com/precious' ) );

		$manager = new Table_Manager();
		$manager->migrate_to_shared_table( 1 );

		$this->assertTrue( $this->table_exists( $shared_table ), 'The shared table must NOT be dropped when processing the main site.' );
		$this->assertSame( 1, $this->count_rows( $shared_table ), 'The shared data must survive.' );

		$statuses = wp_list_pluck( $manager->get_log(), 'status' );
		$this->assertNotContains( 'error', $statuses );
	}

	/**
	 * @testdox Migrating to shared should create the shared table first when it is missing.
	 *
	 * @return void
	 */
	public function test_migrate_to_shared_table_creates_missing_shared_table(): void {
		global $wpdb;

		$shared_table  = Settings::get_shared_multisite_link_table_name();
		$subsite_table = Settings::get_subsite_link_table_name( $this->garren_site_id );

		// Simulate the missing-shared-table state (per-site activated network,
		// or the historic main-site drop bug).
		$wpdb->query( "DROP TABLE IF EXISTS `$shared_table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->create_subsite_table( $subsite_table );
		$this->seed_link( $subsite_table, array( 'url' => 'https://example.com/from-subsite' ) );

		$manager = new Table_Manager();
		$manager->migrate_to_shared_table( $this->garren_site_id );

		$this->assertTrue( $this->table_exists( $shared_table ), 'The shared table should be created on demand.' );
		$this->assertSame( 1, $this->count_rows( $shared_table ), 'The subsite rows should be migrated into the new shared table.' );
		$this->assertFalse( $this->table_exists( $subsite_table ), 'No duplicates, so the subsite table should be dropped.' );
	}

	/**
	 * @testdox Cloning to separate should create the shared table first when it is missing.
	 *
	 * @return void
	 */
	public function test_clone_to_separate_tables_creates_missing_shared_table(): void {
		global $wpdb;

		$shared_table  = Settings::get_shared_multisite_link_table_name();
		$subsite_table = Settings::get_subsite_link_table_name( $this->garren_site_id );

		$wpdb->query( "DROP TABLE IF EXISTS `$shared_table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$manager = new Table_Manager();
		$manager->clone_to_separate_tables( $this->garren_site_id, false, false );

		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		$this->assertTrue( $this->table_exists( $shared_table ), 'The shared source table should be created on demand.' );
		$this->assertTrue( $this->table_exists( $subsite_table ), 'The subsite table should still be created.' );
		$this->assertSame( 0, $this->count_rows( $subsite_table ), 'Cloning from a fresh shared table copies zero rows without error.' );

		$statuses = wp_list_pluck( $manager->get_log(), 'status' );
		$this->assertNotContains( 'error', $statuses );
	}

	/**
	 * @testdox reset_site_links_table(0) should truncate the shared table.
	 *
	 * @return void
	 */
	public function test_reset_site_links_table_truncates_shared_table(): void {
		$shared_table = Settings::get_shared_multisite_link_table_name();

		$this->seed_link( $shared_table );
		$this->seed_link( $shared_table );
		$this->assertSame( 2, $this->count_rows( $shared_table ) );

		$manager = new Table_Manager();
		$manager->reset_site_links_table( 0 );

		$this->assertSame( 0, $this->count_rows( $shared_table ) );
	}

	/**
	 * @testdox Resetting a site whose links table does not exist should be a silent no-op.
	 *
	 * @return void
	 */
	public function test_reset_site_links_table_is_noop_for_missing_table(): void {
		$subsite_table = Settings::get_subsite_link_table_name( $this->garren_site_id );
		$this->assertFalse( $this->table_exists( $subsite_table ) );

		$manager = new Table_Manager();
		$manager->reset_site_links_table( $this->garren_site_id );

		$this->assertFalse( $this->table_exists( $subsite_table ), 'No table should appear from a reset call.' );
		$this->assertSame( array(), $manager->get_log(), 'No log entry should be added for a missing table.' );
	}

	/**
	 * Create the subsite links table directly (same schema as Migration_1).
	 *
	 * @param string $table Table name.
	 *
	 * @return void
	 */
	private function create_subsite_table( string $table ): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		//phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE `$table` (
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

		$this->assertTrue( $this->table_exists( $table ), 'Subsite table should exist after creation.' );
	}
}
