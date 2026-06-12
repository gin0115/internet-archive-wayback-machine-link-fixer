<?php

/**
 * Test Merge_Duplicate_Links_Batch_Event - Multisite
 *
 * Covers the duplicate-merge rules (is_broken/excluded OR'd, checks
 * concatenated, other fields first-won), the per-site pending counter and
 * the drop-subsite-table-on-last-chunk behaviour.
 *
 * Real tables are used (the temporary-table conversion is removed) because
 * the drop path and existence checks cannot see temporary tables.
 *
 * @since 2.0.0
 *
 * @coversDefaultClass \Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Event\Merge_Duplicate_Links_Batch_Event
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Tests\Multisite;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Event\Merge_Duplicate_Links_Batch_Event;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Event\Recheck_Links_Batch_Event;

class Test_Merge_Duplicate_Links_Batch_Event extends \WP_UnitTestCase {

	/**
	 * The Garren (sub) site ID.
	 *
	 * @var integer
	 */
	private $garren_site_id;

	/**
	 * Ensure multisite, real DDL and clean state before each test.
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

		// The drop path checks/drops real tables which temporary tables break.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->clean_state();
	}

	/**
	 * Clean up tables, counters and queued actions after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		$this->clean_state();

		parent::tearDown();
	}

	/**
	 * Shared cleanup of tables, options and queued actions.
	 *
	 * @return void
	 */
	private function clean_state(): void {
		global $wpdb;

		$shared_table  = Settings::get_shared_multisite_link_table_name();
		$subsite_table = Settings::get_subsite_link_table_name( $this->garren_site_id );

		$wpdb->query( "TRUNCATE TABLE `$shared_table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `$subsite_table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		delete_option( Settings::MIGRATIONS_KEY );
		switch_to_blog( $this->garren_site_id );
		delete_option( Settings::MIGRATIONS_KEY );
		restore_current_blog();

		delete_network_option( 0, Merge_Duplicate_Links_Batch_Event::counter_option( $this->garren_site_id ) );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Merge_Duplicate_Links_Batch_Event::HANDLE );
			as_unschedule_all_actions( Recheck_Links_Batch_Event::HANDLE );
		}
	}

	/**
	 * Insert a link row into the shared table and return its ID.
	 *
	 * @param array $row Partial row, merged over defaults.
	 *
	 * @return integer
	 */
	private function seed_shared_link( array $row = array() ): int {
		global $wpdb;

		$wpdb->insert(
			Settings::get_shared_multisite_link_table_name(),
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

		$this->assertSame( '', $wpdb->last_error, 'Seeding a shared link row should not error.' );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Create the subsite links table (same schema as Migration_1).
	 *
	 * @return string The created table name.
	 */
	private function create_subsite_table(): string {
		global $wpdb;

		$table           = Settings::get_subsite_link_table_name( $this->garren_site_id );
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

		return $table;
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
	 * Fetch a shared row by ID.
	 *
	 * @param integer $id Row ID.
	 *
	 * @return array
	 */
	private function get_shared_row( int $id ): array {
		global $wpdb;
		$shared_table = Settings::get_shared_multisite_link_table_name();

		return (array) $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `$shared_table` WHERE id = %d", $id ), //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * @testdox Merging should OR is_broken and excluded, concatenate the checks arrays and leave archived, message, redirect_url and archive_process untouched (first-won).
	 *
	 * @return void
	 */
	public function test_merge_rules(): void {
		$shared_id = $this->seed_shared_link(
			array(
				'url'             => 'https://example.com/merge-me',
				'is_broken'       => 0,
				'excluded'        => 1,
				'checks'          => '["2026-01-01"]',
				'archived'        => 'https://web.archive.org/web/1/x',
				'message'         => 'original message',
				'redirect_url'    => 'https://example.com/redirect',
				'archive_process' => 'done',
			)
		);

		// Keep the counter above zero so the drop path is not triggered here.
		update_network_option( 0, Merge_Duplicate_Links_Batch_Event::counter_option( $this->garren_site_id ), 2 );

		( new Merge_Duplicate_Links_Batch_Event() )(
			$this->garren_site_id,
			array(
				array(
					'url'       => 'https://example.com/merge-me',
					'checks'    => '["2026-02-02"]',
					'is_broken' => 1,
					'excluded'  => 0,
				),
			)
		);

		$row = $this->get_shared_row( $shared_id );

		$this->assertSame( '1', (string) $row['is_broken'], 'is_broken should be OR-merged to broken.' );
		$this->assertSame( '1', (string) $row['excluded'], 'excluded should be OR-merged to excluded.' );
		$this->assertSame(
			array( '2026-01-01', '2026-02-02' ),
			json_decode( (string) $row['checks'], true ),
			'checks arrays should be concatenated, shared first.'
		);

		// First-won fields are untouched.
		$this->assertSame( 'https://web.archive.org/web/1/x', $row['archived'] );
		$this->assertSame( 'original message', $row['message'] );
		$this->assertSame( 'https://example.com/redirect', $row['redirect_url'] );
		$this->assertSame( 'done', $row['archive_process'] );

		// A recheck batch should have been queued for the merged row.
		$this->assertTrue(
			as_has_scheduled_action( Recheck_Links_Batch_Event::HANDLE ),
			'A recheck batch should be queued after a merge.'
		);

		// Counter decremented from 2 to 1, table drop not triggered.
		$counter = get_network_option( 0, Merge_Duplicate_Links_Batch_Event::counter_option( $this->garren_site_id ) );
		$this->assertSame( 1, (int) $counter );
	}

	/**
	 * @testdox The last chunk for a site should remove the counter option and drop the subsite table.
	 *
	 * @return void
	 */
	public function test_last_chunk_drops_subsite_table(): void {
		$subsite_table = $this->create_subsite_table();
		$this->assertTrue( $this->table_exists( $subsite_table ) );

		$this->seed_shared_link( array( 'url' => 'https://example.com/last-chunk' ) );

		update_network_option( 0, Merge_Duplicate_Links_Batch_Event::counter_option( $this->garren_site_id ), 1 );

		( new Merge_Duplicate_Links_Batch_Event() )(
			$this->garren_site_id,
			array(
				array(
					'url'       => 'https://example.com/last-chunk',
					'checks'    => '[]',
					'is_broken' => 0,
					'excluded'  => 0,
				),
			)
		);

		$this->assertFalse(
			get_network_option( 0, Merge_Duplicate_Links_Batch_Event::counter_option( $this->garren_site_id ), false ),
			'The counter option should be deleted after the last chunk.'
		);
		$this->assertFalse( $this->table_exists( $subsite_table ), 'The subsite table should be dropped after the last chunk.' );
	}

	/**
	 * @testdox A chunk whose counter is above one should decrement it and leave the subsite table alone.
	 *
	 * @return void
	 */
	public function test_intermediate_chunk_only_decrements_counter(): void {
		$subsite_table = $this->create_subsite_table();

		$this->seed_shared_link( array( 'url' => 'https://example.com/mid-chunk' ) );

		update_network_option( 0, Merge_Duplicate_Links_Batch_Event::counter_option( $this->garren_site_id ), 3 );

		( new Merge_Duplicate_Links_Batch_Event() )(
			$this->garren_site_id,
			array(
				array(
					'url'       => 'https://example.com/mid-chunk',
					'checks'    => '[]',
					'is_broken' => 0,
					'excluded'  => 0,
				),
			)
		);

		$counter = get_network_option( 0, Merge_Duplicate_Links_Batch_Event::counter_option( $this->garren_site_id ) );
		$this->assertSame( 2, (int) $counter );
		$this->assertTrue( $this->table_exists( $subsite_table ), 'The subsite table must survive intermediate chunks.' );
	}

	/**
	 * @testdox Rows whose URL no longer exists in the shared table should be skipped without queueing a recheck.
	 *
	 * @return void
	 */
	public function test_missing_shared_row_is_skipped(): void {
		update_network_option( 0, Merge_Duplicate_Links_Batch_Event::counter_option( $this->garren_site_id ), 2 );

		( new Merge_Duplicate_Links_Batch_Event() )(
			$this->garren_site_id,
			array(
				array(
					'url'       => 'https://example.com/vanished',
					'checks'    => '[]',
					'is_broken' => 1,
					'excluded'  => 0,
				),
			)
		);

		$this->assertFalse(
			as_has_scheduled_action( Recheck_Links_Batch_Event::HANDLE ),
			'No recheck should be queued when nothing was merged.'
		);

		// The counter still decrements (the chunk was processed).
		$counter = get_network_option( 0, Merge_Duplicate_Links_Batch_Event::counter_option( $this->garren_site_id ) );
		$this->assertSame( 1, (int) $counter );
	}

	/**
	 * @testdox Invalid checks JSON on either side should be treated as an empty list rather than erroring.
	 *
	 * @return void
	 */
	public function test_invalid_checks_json_treated_as_empty(): void {
		$shared_id = $this->seed_shared_link(
			array(
				'url'    => 'https://example.com/bad-json',
				'checks' => '["kept"]',
			)
		);

		update_network_option( 0, Merge_Duplicate_Links_Batch_Event::counter_option( $this->garren_site_id ), 2 );

		( new Merge_Duplicate_Links_Batch_Event() )(
			$this->garren_site_id,
			array(
				array(
					'url'       => 'https://example.com/bad-json',
					'checks'    => 'this is not json',
					'is_broken' => 0,
					'excluded'  => 0,
				),
			)
		);

		$row = $this->get_shared_row( $shared_id );
		$this->assertSame(
			array( 'kept' ),
			json_decode( (string) $row['checks'], true ),
			'Invalid subsite checks should merge as an empty array.'
		);
	}

	/**
	 * @testdox Rows with an empty URL should be skipped entirely.
	 *
	 * @return void
	 */
	public function test_blank_url_rows_are_skipped(): void {
		update_network_option( 0, Merge_Duplicate_Links_Batch_Event::counter_option( $this->garren_site_id ), 2 );

		( new Merge_Duplicate_Links_Batch_Event() )(
			$this->garren_site_id,
			array(
				array(
					'url'       => '',
					'checks'    => '[]',
					'is_broken' => 1,
					'excluded'  => 1,
				),
			)
		);

		$this->assertFalse(
			as_has_scheduled_action( Recheck_Links_Batch_Event::HANDLE ),
			'No recheck should be queued for blank URL rows.'
		);
	}
}
