<?php

/**
 * Test Clone AJAX endpoints - Multisite
 *
 * Covers the three clone endpoints: Clone_Start_Ajax (auth, validation,
 * direction selection, state creation), Clone_Process_Site_Ajax (processing,
 * completion mode-flip, error paths) and Clone_Dismiss_Ajax (state removal).
 *
 * The endpoints are registered directly in setUp because their production
 * registration runs through Integrations::initialize(), which is gated on
 * site availability (plan section 4.10).
 *
 * @since 2.0.0
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Tests\Multisite;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Multisite;
use Internet_Archive\Wayback_Machine_Link_Fixer\Ajax\Clone_Start_Ajax;
use Internet_Archive\Wayback_Machine_Link_Fixer\Ajax\Clone_Dismiss_Ajax;
use Internet_Archive\Wayback_Machine_Link_Fixer\Ajax\Clone_Process_Site_Ajax;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Table_Clone_State;

class Test_Clone_Ajax extends \WP_Ajax_UnitTestCase {

	/**
	 * The Garren (sub) site ID.
	 *
	 * @var integer
	 */
	private $garren_site_id;

	/**
	 * Ensure multisite, registered endpoints and clean state before each test.
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

		// Register the endpoints (production registration is availability-gated).
		Clone_Start_Ajax::register_ajax_call();
		Clone_Process_Site_Ajax::register_ajax_call();
		Clone_Dismiss_Ajax::register_ajax_call();

		// Real tables are needed for the process endpoint.
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
		$_POST = array();

		parent::tearDown();
	}

	/**
	 * Shared cleanup of state, tables and options.
	 *
	 * @return void
	 */
	private function clean_state(): void {
		global $wpdb;

		Table_Clone_State::delete();
		delete_network_option( get_current_network_id(), Settings::MULTISITE_LINKS_TABLE_MODE );

		$shared_table  = Settings::get_shared_multisite_link_table_name();
		$subsite_table = Settings::get_subsite_link_table_name( $this->garren_site_id );

		$wpdb->query( "TRUNCATE TABLE `$shared_table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `$subsite_table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		delete_option( Settings::MIGRATIONS_KEY );
		switch_to_blog( $this->garren_site_id );
		delete_option( Settings::MIGRATIONS_KEY );
		restore_current_blog();
	}

	/**
	 * Log in as a super admin (required for manage_network_options).
	 *
	 * @return integer The user ID.
	 */
	private function act_as_super_admin(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Run an AJAX action and return the decoded JSON response.
	 *
	 * @param string $action The un-prefixed AJAX action.
	 *
	 * @return array
	 */
	private function dispatch( string $action ): array {
		try {
			$this->_handleAjax( $action );
		} catch ( \WPAjaxDieContinueException $e ) {
			// Expected: wp_send_json_* ends with an (empty) wp_die.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response, 'AJAX endpoint should respond with JSON.' );

		return $response;
	}

	/**
	 * @testdox Clone start should reject requests with a missing or invalid nonce.
	 *
	 * @return void
	 */
	public function test_start_rejects_invalid_nonce(): void {
		$this->act_as_super_admin();

		$_POST['nonce'] = 'definitely-not-valid';
		$_POST['sites'] = wp_json_encode( array( $this->garren_site_id ) );

		$response = $this->dispatch( Clone_Start_Ajax::ACTION );

		$this->assertFalse( $response['success'] );
		$this->assertNull( Table_Clone_State::load(), 'No state should be created on auth failure.' );
	}

	/**
	 * @testdox Clone start should reject users without the manage_network_options capability.
	 *
	 * @return void
	 */
	public function test_start_rejects_non_network_admin(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$_POST['nonce'] = wp_create_nonce( Clone_Start_Ajax::NONCE );
		$_POST['sites'] = wp_json_encode( array( $this->garren_site_id ) );

		$response = $this->dispatch( Clone_Start_Ajax::ACTION );

		$this->assertFalse( $response['success'] );
		$this->assertNull( Table_Clone_State::load(), 'No state should be created without capability.' );
	}

	/**
	 * @testdox Clone start should reject an empty site selection.
	 *
	 * @return void
	 */
	public function test_start_rejects_empty_sites(): void {
		$this->act_as_super_admin();

		$_POST['nonce']    = wp_create_nonce( Clone_Start_Ajax::NONCE );
		$_POST['sites']    = '[]';
		$_POST['new_mode'] = 'separate';

		$response = $this->dispatch( Clone_Start_Ajax::ACTION );

		$this->assertFalse( $response['success'] );
		$this->assertNull( Table_Clone_State::load() );
	}

	/**
	 * @testdox Clone start should reject an unknown target mode.
	 *
	 * @return void
	 */
	public function test_start_rejects_invalid_mode(): void {
		$this->act_as_super_admin();

		$_POST['nonce']    = wp_create_nonce( Clone_Start_Ajax::NONCE );
		$_POST['sites']    = wp_json_encode( array( $this->garren_site_id ) );
		$_POST['new_mode'] = 'sideways';

		$response = $this->dispatch( Clone_Start_Ajax::ACTION );

		$this->assertFalse( $response['success'] );
		$this->assertNull( Table_Clone_State::load() );
	}

	/**
	 * @testdox Starting a clone towards separate mode should persist a running shared-to-per-site state with the posted options.
	 *
	 * @return void
	 */
	public function test_start_separate_direction_creates_running_state(): void {
		$this->act_as_super_admin();

		$_POST['nonce']          = wp_create_nonce( Clone_Start_Ajax::NONCE );
		$_POST['sites']          = wp_json_encode( array( $this->garren_site_id ) );
		$_POST['new_mode']       = 'separate';
		$_POST['reset_checks']   = '1';
		$_POST['reset_source']   = '0';
		$_POST['share_settings'] = '0';

		$response = $this->dispatch( Clone_Start_Ajax::ACTION );

		$this->assertTrue( $response['success'] );

		$state = Table_Clone_State::load();
		$this->assertInstanceOf( Table_Clone_State::class, $state );
		$this->assertSame( Table_Clone_State::TYPE_FROM_GLOBAL_TO_PER_SITE, $state->get_clone_type() );
		$this->assertSame( Table_Clone_State::STATUS_RUNNING, $state->get_status() );
		$this->assertSame( array( $this->garren_site_id ), $state->get_sites_to_process() );
		$this->assertTrue( $state->get_reset_checks() );
		$this->assertFalse( $state->get_reset_source_table() );
		$this->assertFalse( $state->get_share_settings() );
	}

	/**
	 * @testdox Starting a clone towards shared mode should persist a running per-site-to-shared state.
	 *
	 * @return void
	 */
	public function test_start_shared_direction_creates_running_state(): void {
		$this->act_as_super_admin();

		$_POST['nonce']    = wp_create_nonce( Clone_Start_Ajax::NONCE );
		$_POST['sites']    = wp_json_encode( array( $this->garren_site_id ) );
		$_POST['new_mode'] = 'shared';

		$response = $this->dispatch( Clone_Start_Ajax::ACTION );

		$this->assertTrue( $response['success'] );

		$state = Table_Clone_State::load();
		$this->assertSame( Table_Clone_State::TYPE_FROM_PER_SITE_TO_GLOBAL, $state->get_clone_type() );
		$this->assertSame( Table_Clone_State::STATUS_RUNNING, $state->get_status() );
	}

	/**
	 * @testdox Processing the final site of a shared-to-separate clone should copy the rows, mark the state completed and flip the network mode to separate.
	 *
	 * @return void
	 */
	public function test_process_site_completes_clone_and_flips_mode(): void {
		global $wpdb;

		$this->act_as_super_admin();

		// Starting mode: shared.
		Settings::set_multisite_links_table_mode( Multisite::SHARED_LINKS_TABLE_MODE );

		// Seed the shared table with a row to clone.
		$shared_table = Settings::get_shared_multisite_link_table_name();
		$wpdb->insert(
			$shared_table,
			array(
				'url'    => 'https://example.com/clone-me',
				'checks' => '[]',
			)
		);

		// A running clone state for just the Garren site.
		$state = Table_Clone_State::from_global( array( $this->garren_site_id ) );
		$state->set_status( Table_Clone_State::STATUS_RUNNING );
		$state->save();

		$_POST['nonce']        = wp_create_nonce( Clone_Process_Site_Ajax::NONCE );
		$_POST['site_id']      = (string) $this->garren_site_id;
		$_POST['reset_checks'] = '0';
		$_POST['reset_source'] = '0';

		$response = $this->dispatch( Clone_Process_Site_Ajax::ACTION );

		$this->assertTrue( $response['success'] );
		$this->assertSame( $this->garren_site_id, $response['data']['site_id'] );

		// The subsite table now exists with the cloned row.
		$subsite_table = Settings::get_subsite_link_table_name( $this->garren_site_id );
		$count         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$subsite_table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 1, $count );

		// State completed, site recorded, mode flipped.
		$saved = Table_Clone_State::load();
		$this->assertSame( Table_Clone_State::STATUS_COMPLETED, $saved->get_status() );
		$this->assertSame( array( $this->garren_site_id ), $saved->get_sites_completed() );
		$this->assertSame( Multisite::SEPARATE_LINKS_TABLE_MODE, Settings::get_multisite_links_table_mode() );
	}

	/**
	 * @testdox Processing should reject a missing site ID.
	 *
	 * @return void
	 */
	public function test_process_site_rejects_missing_site_id(): void {
		$this->act_as_super_admin();

		$_POST['nonce'] = wp_create_nonce( Clone_Process_Site_Ajax::NONCE );

		$response = $this->dispatch( Clone_Process_Site_Ajax::ACTION );

		$this->assertFalse( $response['success'] );
	}

	/**
	 * @testdox Processing an unknown site should respond with an error, log it to the state and not flip the mode.
	 *
	 * @return void
	 */
	public function test_process_site_unknown_site_errors_without_mode_flip(): void {
		$this->act_as_super_admin();

		Settings::set_multisite_links_table_mode( Multisite::SHARED_LINKS_TABLE_MODE );

		$state = Table_Clone_State::from_global( array( 99999 ) );
		$state->set_status( Table_Clone_State::STATUS_RUNNING );
		$state->save();

		$_POST['nonce']   = wp_create_nonce( Clone_Process_Site_Ajax::NONCE );
		$_POST['site_id'] = '99999';

		$response = $this->dispatch( Clone_Process_Site_Ajax::ACTION );

		$this->assertFalse( $response['success'] );

		// State keeps running, gains an error log entry, mode unchanged.
		$saved = Table_Clone_State::load();
		$this->assertSame( Table_Clone_State::STATUS_RUNNING, $saved->get_status() );
		$this->assertSame( array(), $saved->get_sites_completed() );
		$this->assertStringContainsString( 'error', strtolower( implode( ' ', $saved->get_log() ) ) );
		$this->assertSame( Multisite::SHARED_LINKS_TABLE_MODE, Settings::get_multisite_links_table_mode() );
	}

	/**
	 * @testdox Dismiss should delete the persisted clone state.
	 *
	 * @return void
	 */
	public function test_dismiss_deletes_state(): void {
		$this->act_as_super_admin();

		$state = Table_Clone_State::from_global( array( $this->garren_site_id ) );
		$state->save();
		$this->assertInstanceOf( Table_Clone_State::class, Table_Clone_State::load() );

		$_POST['nonce'] = wp_create_nonce( Clone_Dismiss_Ajax::NONCE );

		$response = $this->dispatch( Clone_Dismiss_Ajax::ACTION );

		$this->assertTrue( $response['success'] );
		$this->assertNull( Table_Clone_State::load() );
	}

	/**
	 * @testdox Dismiss should reject an invalid nonce and keep the state.
	 *
	 * @return void
	 */
	public function test_dismiss_rejects_invalid_nonce(): void {
		$this->act_as_super_admin();

		$state = Table_Clone_State::from_global( array( $this->garren_site_id ) );
		$state->save();

		$_POST['nonce'] = 'nope';

		$response = $this->dispatch( Clone_Dismiss_Ajax::ACTION );

		$this->assertFalse( $response['success'] );
		$this->assertInstanceOf( Table_Clone_State::class, Table_Clone_State::load(), 'State should survive a rejected dismiss.' );
	}
}
