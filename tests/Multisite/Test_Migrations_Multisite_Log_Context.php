<?php

/**
 * Test Migrations multisite log context - Multisite
 *
 * Regression coverage for the wrong-blog migration-log bookkeeping (plan
 * section 4.4): multisite_up()/multisite_down() must read and write the
 * TARGET site's migration log no matter which blog the caller is on.
 *
 * @since 2.0.0
 *
 * @coversDefaultClass \Internet_Archive\Wayback_Machine_Link_Fixer\Migration\Migrations
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Tests\Multisite;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Migration\Migrations;

class Test_Migrations_Multisite_Log_Context extends \WP_UnitTestCase {

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

		// Real tables are created/dropped by the migrations.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->clean_state();
	}

	/**
	 * Clean up after each test.
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
	 * Drop the garren table and clear both sites' blog-level migration logs.
	 *
	 * @return void
	 */
	private function clean_state(): void {
		global $wpdb;

		$subsite_table = Settings::get_subsite_link_table_name( $this->garren_site_id );
		$wpdb->query( "DROP TABLE IF EXISTS `$subsite_table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		delete_option( Settings::MIGRATIONS_KEY );
		switch_to_blog( $this->garren_site_id );
		delete_option( Settings::MIGRATIONS_KEY );
		restore_current_blog();
	}

	/**
	 * Read a site's blog-level migration log.
	 *
	 * @param integer $site_id The site ID.
	 *
	 * @return array
	 */
	private function get_site_migration_log( int $site_id ): array {
		switch_to_blog( $site_id );
		$log = Settings::migrations( true );
		restore_current_blog();

		return $log;
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
	 * @testdox multisite_up() called from the main site should create the target site's table and write the TARGET site's migration log, not the caller's.
	 *
	 * @return void
	 */
	public function test_multisite_up_writes_target_site_log(): void {
		$main_log_before = Settings::migrations( true );

		// Called from MAIN site context — no prior switch_to_blog.
		Migrations::multisite_up( $this->garren_site_id );

		$this->assertTrue(
			$this->table_exists( Settings::get_subsite_link_table_name( $this->garren_site_id ) ),
			'The target site table should be created.'
		);

		$this->assertSame(
			Migrations::$migrations,
			$this->get_site_migration_log( $this->garren_site_id ),
			'The migration log should be recorded on the TARGET site.'
		);

		$this->assertSame(
			$main_log_before,
			Settings::migrations( true ),
			'The caller (main site) log should be untouched.'
		);
	}

	/**
	 * @testdox multisite_down() called from the main site should drop the target site's table and clear the TARGET site's migration log, not the caller's.
	 *
	 * @return void
	 */
	public function test_multisite_down_clears_target_site_log(): void {
		// Build up the target site first.
		Migrations::multisite_up( $this->garren_site_id );
		$this->assertNotEmpty( $this->get_site_migration_log( $this->garren_site_id ) );

		// Give the MAIN site a sentinel log entry that must survive.
		Settings::update_migrations( array( 'sentinel-entry' ), true );

		// Called from MAIN site context — this is exactly the AS-runner
		// scenario that used to poison the bookkeeping.
		Migrations::multisite_down( $this->garren_site_id );

		$this->assertFalse(
			$this->table_exists( Settings::get_subsite_link_table_name( $this->garren_site_id ) ),
			'The target site table should be dropped.'
		);

		$this->assertSame(
			array(),
			$this->get_site_migration_log( $this->garren_site_id ),
			'The TARGET site log should be cleared so the next multisite_up recreates the table.'
		);

		$this->assertSame(
			array( 'sentinel-entry' ),
			Settings::migrations( true ),
			'The caller (main site) log should be untouched.'
		);
	}
}
