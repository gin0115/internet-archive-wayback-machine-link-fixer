<?php

/**
 * Action scheduler event for re-validating a batch of link IDs after a merge.
 *
 * Receives a batch of shared-table link IDs, fans out a
 * Link_Access_Validator_Event task per link.
 *
 * @since 1.4.0
 *
 * @package Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Event
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Event;

use Internet_Archive\Wayback_Machine_Link_Fixer\Event\Link_Access_Validator_Event;

defined( 'ABSPATH' ) || exit;

/**
 * Recheck Links Batch Event.
 */
class Recheck_Links_Batch_Event {

	public const HANDLE = 'iawmlf_recheck_links_batch';

	/**
	 * Queue a batch of link IDs for re-validation.
	 *
	 * @param array<int, integer> $link_ids Link IDs from the shared table.
	 *
	 * @return void
	 */
	public static function add_to_queue( array $link_ids ): void {
		$link_ids = array_values( array_unique( array_map( 'intval', $link_ids ) ) );
		if ( empty( $link_ids ) ) {
			return;
		}

		$target_site_id = (int) apply_filters( 'iawmlf_merge_task_site_id', 1 );

		switch_to_blog( $target_site_id );
		\as_enqueue_async_action(
			self::HANDLE,
			array( 'link_ids' => $link_ids ),
			'internet-archive-wayback-machine-link-fixer'
		);
		restore_current_blog();
	}

	/**
	 * Invoke the event.
	 *
	 * @param array<int, integer> $link_ids Link IDs from the shared table.
	 *
	 * @return void
	 */
	public function __invoke( array $link_ids ): void {
		foreach ( $link_ids as $link_id ) {
			$link_id = (int) $link_id;
			if ( $link_id <= 0 ) {
				continue;
			}
			Link_Access_Validator_Event::add_to_queue( $link_id );
		}
	}
}
