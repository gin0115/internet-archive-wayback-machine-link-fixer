<?php

/**
 * Test Recheck_Links_Batch_Event - Multisite
 *
 * Covers the queueing rules (dedupe, int casting, empty short-circuit) and
 * the fan-out of Link_Access_Validator_Event tasks per valid link ID.
 *
 * @since 2.0.0
 *
 * @coversDefaultClass \Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Event\Recheck_Links_Batch_Event
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Tests\Multisite;

use Internet_Archive\Wayback_Machine_Link_Fixer\Event\Link_Access_Validator_Event;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Event\Recheck_Links_Batch_Event;

class Test_Recheck_Links_Batch_Event extends \WP_UnitTestCase {

	/**
	 * Ensure multisite and a clean queue before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure we're in multisite mode.
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite to be enabled.' );
		}

		$this->clear_queue();
	}

	/**
	 * Clean the queue after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		$this->clear_queue();

		parent::tearDown();
	}

	/**
	 * Unschedule all actions touched by these tests.
	 *
	 * @return void
	 */
	private function clear_queue(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Recheck_Links_Batch_Event::HANDLE );
			as_unschedule_all_actions( Link_Access_Validator_Event::HANDLE );
		}
	}

	/**
	 * @testdox Queueing link IDs should dedupe and int-cast them into a single batch action.
	 *
	 * @return void
	 */
	public function test_add_to_queue_dedupes_and_casts(): void {
		Recheck_Links_Batch_Event::add_to_queue( array( 5, '5', 7, 5 ) );

		$this->assertTrue( as_has_scheduled_action( Recheck_Links_Batch_Event::HANDLE ) );

		// Exactly one batch action with the deduped, int-cast IDs.
		$actions = as_get_scheduled_actions(
			array(
				'hook'   => Recheck_Links_Batch_Event::HANDLE,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		$this->assertCount( 1, $actions );

		$action = \ActionScheduler::store()->fetch_action( (string) reset( $actions ) );
		$this->assertSame( array( 'link_ids' => array( 5, 7 ) ), $action->get_args() );
	}

	/**
	 * @testdox Queueing an empty or all-invalid ID list should not schedule anything.
	 *
	 * @return void
	 */
	public function test_add_to_queue_short_circuits_on_empty(): void {
		Recheck_Links_Batch_Event::add_to_queue( array() );

		$this->assertFalse( as_has_scheduled_action( Recheck_Links_Batch_Event::HANDLE ) );
	}

	/**
	 * @testdox Invoking the batch should fan out one validator task per positive link ID, skipping zero and negative IDs.
	 *
	 * @return void
	 */
	public function test_invoke_fans_out_validator_tasks(): void {
		( new Recheck_Links_Batch_Event() )( array( 3, 0, -2, 9 ) );

		$actions = as_get_scheduled_actions(
			array(
				'hook'   => Link_Access_Validator_Event::HANDLE,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);

		$this->assertCount( 2, $actions, 'Only the two valid IDs should be queued.' );

		$queued_ids = array();
		foreach ( $actions as $action_id ) {
			$args         = \ActionScheduler::store()->fetch_action( (string) $action_id )->get_args();
			$queued_ids[] = $args['link_id'];
		}

		sort( $queued_ids );
		$this->assertSame( array( 3, 9 ), $queued_ids );
	}
}
