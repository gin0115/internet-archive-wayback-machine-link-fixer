<?php

/**
 * Test Table_Clone_State - Multisite
 *
 * Covers the resumable clone-state model: factories, mutation helpers,
 * JSON round-trip, progress calculation and network-option persistence.
 *
 * @since 2.0.0
 *
 * @coversDefaultClass \Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Table_Clone_State
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Tests\Multisite;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Table_Clone_State;

class Test_Table_Clone_State extends \WP_UnitTestCase {

	/**
	 * Ensure multisite and a clean persisted state before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure we're in multisite mode.
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite to be enabled.' );
		}

		// Start every test without a persisted state.
		delete_network_option( 0, Settings::TABLE_CLONE_STATE );
	}

	/**
	 * Remove any persisted state after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_network_option( 0, Settings::TABLE_CLONE_STATE );

		parent::tearDown();
	}

	/**
	 * @testdox A state constructed with no arguments should hold the documented defaults (global to per-site, idle, no sites, no log, share settings on).
	 *
	 * @return void
	 */
	public function test_constructor_defaults(): void {
		$state = new Table_Clone_State();

		$this->assertSame( Table_Clone_State::TYPE_FROM_GLOBAL_TO_PER_SITE, $state->get_clone_type() );
		$this->assertSame( Table_Clone_State::STATUS_IDLE, $state->get_status() );
		$this->assertSame( array(), $state->get_sites_to_process() );
		$this->assertSame( array(), $state->get_sites_completed() );
		$this->assertSame( 0, $state->get_current_blog_id() );
		$this->assertSame( array(), $state->get_log() );
		$this->assertFalse( $state->get_reset_checks() );
		$this->assertFalse( $state->get_reset_source_table() );
		$this->assertTrue( $state->get_share_settings() );
	}

	/**
	 * @testdox The from_global() factory should create an idle shared-to-separate state holding the passed sites and options.
	 *
	 * @return void
	 */
	public function test_from_global_factory(): void {
		$state = Table_Clone_State::from_global( array( 2, 3 ), true, true, false );

		$this->assertSame( Table_Clone_State::TYPE_FROM_GLOBAL_TO_PER_SITE, $state->get_clone_type() );
		$this->assertSame( Table_Clone_State::STATUS_IDLE, $state->get_status() );
		$this->assertSame( array( 2, 3 ), $state->get_sites_to_process() );
		$this->assertSame( array(), $state->get_sites_completed() );
		$this->assertTrue( $state->get_reset_checks() );
		$this->assertTrue( $state->get_reset_source_table() );
		$this->assertFalse( $state->get_share_settings() );
	}

	/**
	 * @testdox The to_global() factory should create an idle separate-to-shared state holding the passed sites and options.
	 *
	 * @return void
	 */
	public function test_to_global_factory(): void {
		$state = Table_Clone_State::to_global( array( 4, 5, 6 ) );

		$this->assertSame( Table_Clone_State::TYPE_FROM_PER_SITE_TO_GLOBAL, $state->get_clone_type() );
		$this->assertSame( Table_Clone_State::STATUS_IDLE, $state->get_status() );
		$this->assertSame( array( 4, 5, 6 ), $state->get_sites_to_process() );
		$this->assertFalse( $state->get_reset_checks() );
		$this->assertFalse( $state->get_reset_source_table() );
		$this->assertTrue( $state->get_share_settings() );
	}

	/**
	 * @testdox Adding a completed site should append it once, ignoring duplicate additions.
	 *
	 * @return void
	 */
	public function test_add_completed_site_ignores_duplicates(): void {
		$state = Table_Clone_State::from_global( array( 2, 3 ) );

		$state->add_completed_site( 2 );
		$state->add_completed_site( 2 );
		$state->add_completed_site( 3 );

		$this->assertSame( array( 2, 3 ), $state->get_sites_completed() );
		$this->assertSame( 2, $state->get_done_sites() );
	}

	/**
	 * @testdox Progress should be 0 with no sites, track completed/total as a percentage and round to 2 decimal places.
	 *
	 * @return void
	 */
	public function test_progress_calculation(): void {
		// No sites at all = 0 (guards against division by zero).
		$empty = new Table_Clone_State();
		$this->assertSame( 0.0, $empty->get_progress() );

		// 1 of 3 done = 33.33 (rounded).
		$state = Table_Clone_State::from_global( array( 2, 3, 4 ) );
		$state->add_completed_site( 2 );
		$this->assertSame( 33.33, $state->get_progress() );

		// All done = 100.
		$state->add_completed_site( 3 );
		$state->add_completed_site( 4 );
		$this->assertSame( 100.0, $state->get_progress() );
	}

	/**
	 * @testdox The status helpers should report running and completed based on the current status.
	 *
	 * @return void
	 */
	public function test_status_helpers(): void {
		$state = new Table_Clone_State();

		$this->assertFalse( $state->is_running() );
		$this->assertFalse( $state->is_completed() );

		$state->set_status( Table_Clone_State::STATUS_RUNNING );
		$this->assertTrue( $state->is_running() );
		$this->assertFalse( $state->is_completed() );

		$state->set_status( Table_Clone_State::STATUS_COMPLETED );
		$this->assertFalse( $state->is_running() );
		$this->assertTrue( $state->is_completed() );

		$state->set_status( Table_Clone_State::STATUS_ERROR );
		$this->assertFalse( $state->is_running() );
		$this->assertFalse( $state->is_completed() );
	}

	/**
	 * @testdox Log entries should be prefixed with their type and returned in insertion order.
	 *
	 * @return void
	 */
	public function test_add_log_formats_entries(): void {
		$state = new Table_Clone_State();

		$state->add_log( 'Started clone' );
		$state->add_log( 'Table missing', 'error' );

		$this->assertSame(
			array(
				'[info] Started clone',
				'[error] Table missing',
			),
			$state->get_log()
		);
	}

	/**
	 * @testdox A fully populated state should survive a to_json/from_json round trip with every field intact.
	 *
	 * @return void
	 */
	public function test_json_round_trip(): void {
		$state = new Table_Clone_State(
			Table_Clone_State::TYPE_FROM_PER_SITE_TO_GLOBAL,
			Table_Clone_State::STATUS_RUNNING,
			array( 2, 3 ),
			array( 2 ),
			3,
			array( '[info] processing' ),
			true,
			true,
			false
		);

		$restored = Table_Clone_State::from_json( $state->to_json() );

		$this->assertInstanceOf( Table_Clone_State::class, $restored );
		$this->assertSame( Table_Clone_State::TYPE_FROM_PER_SITE_TO_GLOBAL, $restored->get_clone_type() );
		$this->assertSame( Table_Clone_State::STATUS_RUNNING, $restored->get_status() );
		$this->assertSame( array( 2, 3 ), $restored->get_sites_to_process() );
		$this->assertSame( array( 2 ), $restored->get_sites_completed() );
		$this->assertSame( 3, $restored->get_current_blog_id() );
		$this->assertSame( array( '[info] processing' ), $restored->get_log() );
		$this->assertTrue( $restored->get_reset_checks() );
		$this->assertTrue( $restored->get_reset_source_table() );
		$this->assertFalse( $restored->get_share_settings() );
	}

	/**
	 * @testdox from_json() should return null for content that does not decode to an array.
	 *
	 * @return void
	 */
	public function test_from_json_rejects_invalid_json(): void {
		$this->assertNull( Table_Clone_State::from_json( 'not json at all' ) );
		$this->assertNull( Table_Clone_State::from_json( '"just a string"' ) );
		$this->assertNull( Table_Clone_State::from_json( '123' ) );
	}

	/**
	 * @testdox from_json() should fall back to defaults for missing keys (idle status, empty collections, share settings on).
	 *
	 * @return void
	 */
	public function test_from_json_defaults_missing_keys(): void {
		$state = Table_Clone_State::from_json( '{}' );

		$this->assertInstanceOf( Table_Clone_State::class, $state );
		$this->assertSame( '', $state->get_clone_type() );
		$this->assertSame( Table_Clone_State::STATUS_IDLE, $state->get_status() );
		$this->assertSame( array(), $state->get_sites_to_process() );
		$this->assertSame( array(), $state->get_sites_completed() );
		$this->assertSame( 0, $state->get_current_blog_id() );
		$this->assertSame( array(), $state->get_log() );
		$this->assertFalse( $state->get_reset_checks() );
		$this->assertFalse( $state->get_reset_source_table() );
		$this->assertTrue( $state->get_share_settings() );
	}

	/**
	 * @testdox from_json() should sanitize site ID lists to positive integers.
	 *
	 * @return void
	 */
	public function test_from_json_sanitizes_site_ids(): void {
		$json = wp_json_encode(
			array(
				'clone_type'       => Table_Clone_State::TYPE_FROM_GLOBAL_TO_PER_SITE,
				'status'           => Table_Clone_State::STATUS_RUNNING,
				'sites_to_process' => array( '2', 3, '-4' ),
				'sites_completed'  => array( '2' ),
			)
		);

		$state = Table_Clone_State::from_json( $json );

		$this->assertSame( array( 2, 3, 4 ), $state->get_sites_to_process(), 'Site IDs should be cast with absint()' );
		$this->assertSame( array( 2 ), $state->get_sites_completed() );
	}

	/**
	 * @testdox save() should persist the state to the network option and load() should restore an identical state.
	 *
	 * @return void
	 */
	public function test_save_and_load_round_trip(): void {
		$state = Table_Clone_State::from_global( array( 2, 3 ), true, false, false );
		$state->set_status( Table_Clone_State::STATUS_RUNNING )
			->set_current_blog_id( 2 )
			->add_completed_site( 2 )
			->add_log( 'site 2 done' );

		$this->assertTrue( $state->save() );

		// The raw option should hold the JSON representation.
		$this->assertSame( $state->to_json(), get_network_option( 0, Settings::TABLE_CLONE_STATE ) );

		$loaded = Table_Clone_State::load();

		$this->assertInstanceOf( Table_Clone_State::class, $loaded );
		$this->assertSame( Table_Clone_State::TYPE_FROM_GLOBAL_TO_PER_SITE, $loaded->get_clone_type() );
		$this->assertSame( Table_Clone_State::STATUS_RUNNING, $loaded->get_status() );
		$this->assertSame( array( 2, 3 ), $loaded->get_sites_to_process() );
		$this->assertSame( array( 2 ), $loaded->get_sites_completed() );
		$this->assertSame( 2, $loaded->get_current_blog_id() );
		$this->assertSame( array( '[info] site 2 done' ), $loaded->get_log() );
		$this->assertTrue( $loaded->get_reset_checks() );
		$this->assertFalse( $loaded->get_reset_source_table() );
		$this->assertFalse( $loaded->get_share_settings() );
	}

	/**
	 * @testdox load() should return null when no state has ever been saved.
	 *
	 * @return void
	 */
	public function test_load_returns_null_without_saved_state(): void {
		$this->assertNull( Table_Clone_State::load() );
	}

	/**
	 * @testdox delete() should remove the persisted state so a subsequent load() returns null.
	 *
	 * @return void
	 */
	public function test_delete_removes_persisted_state(): void {
		$state = Table_Clone_State::from_global( array( 2 ) );
		$state->save();

		$this->assertInstanceOf( Table_Clone_State::class, Table_Clone_State::load() );

		$this->assertTrue( Table_Clone_State::delete() );
		$this->assertNull( Table_Clone_State::load() );
	}

	/**
	 * @testdox clear() should reset type, status, sites, current blog and log, while leaving the clone options untouched.
	 *
	 * @return void
	 */
	public function test_clear_resets_progress_but_keeps_options(): void {
		$state = Table_Clone_State::from_global( array( 2, 3 ), true, true, false );
		$state->set_status( Table_Clone_State::STATUS_ERROR )
			->set_current_blog_id( 3 )
			->add_completed_site( 2 )
			->add_log( 'boom', 'error' );

		$state->clear();

		$this->assertSame( '', $state->get_clone_type() );
		$this->assertSame( Table_Clone_State::STATUS_IDLE, $state->get_status() );
		$this->assertSame( array(), $state->get_sites_to_process() );
		$this->assertSame( array(), $state->get_sites_completed() );
		$this->assertSame( 0, $state->get_current_blog_id() );
		$this->assertSame( array(), $state->get_log() );

		// The clone options are deliberately not reset by clear().
		$this->assertTrue( $state->get_reset_checks() );
		$this->assertTrue( $state->get_reset_source_table() );
		$this->assertFalse( $state->get_share_settings() );
	}
}
