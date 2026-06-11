<?php

/**
 * Action scheduler event for merging a batch of duplicate links from a subsite
 * table into the shared table, after a separate-to-shared migration.
 *
 * Each task handles one chunk of duplicates for a single site. When the last
 * chunk for a site completes, the subsite table is dropped via
 * Migrations::multisite_down().
 *
 * @since 1.4.0
 *
 * @package Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Event
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Event;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Migration\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * Merge Duplicate Links Batch Event.
 */
class Merge_Duplicate_Links_Batch_Event {

	public const HANDLE = 'iawmlf_merge_duplicate_links_batch';

	/**
	 * Network option name prefix for per-site pending-merge counters.
	 *
	 * Stored as: iawmlf_merge_pending_{site_id} => int
	 */
	public const COUNTER_OPTION_PREFIX = 'iawmlf_merge_pending_';

	/**
	 * Queue a chunk for merging.
	 *
	 * @param integer                                                                            $site_id The source site ID (the subsite data came from).
	 * @param array<int, array{url: string, checks: string, is_broken: int, excluded: int}>      $chunk   Rows to merge.
	 *
	 * @return void
	 */
	public static function add_to_queue( int $site_id, array $chunk ): void {
		// Queue on the main site (filterable) so a reliable cron picks it up.
		$target_site_id = (int) apply_filters( 'iawmlf_merge_task_site_id', 1 );

		switch_to_blog( $target_site_id );
		\as_enqueue_async_action(
			self::HANDLE,
			array(
				'site_id' => $site_id,
				'chunk'   => $chunk,
			),
			'internet-archive-wayback-machine-link-fixer'
		);
		restore_current_blog();
	}

	/**
	 * Get the per-site counter option name.
	 *
	 * @param integer $site_id The site ID.
	 *
	 * @return string
	 */
	public static function counter_option( int $site_id ): string {
		return self::COUNTER_OPTION_PREFIX . $site_id;
	}

	/**
	 * Invoke the event.
	 *
	 * @param integer $site_id The source site ID.
	 * @param array   $chunk   The batch of duplicate rows to merge.
	 *
	 * @return void
	 */
	public function __invoke( int $site_id, array $chunk ): void {
		global $wpdb;

		$shared_table   = Settings::get_shared_multisite_link_table_name();
		$merged_ids     = array();
		$counter_option = self::counter_option( $site_id );

		try {
			foreach ( $chunk as $row ) {
				$url       = (string) ( $row['url'] ?? '' );
				$sub_is_broken = (int) ( $row['is_broken'] ?? 0 );
				$sub_excluded  = (int) ( $row['excluded'] ?? 0 );
				$sub_checks    = $row['checks'] ?? '[]';

				if ( '' === $url ) {
					continue;
				}

				// Find existing shared row by URL.
				$shared_row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, is_broken, excluded, checks FROM `$shared_table` WHERE url = %s LIMIT 1", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$url
					),
					ARRAY_A
				);

				if ( null === $shared_row ) {
					// Lost the race — row disappeared. Skip.
					continue;
				}

				$shared_id        = (int) $shared_row['id'];
				$merged_is_broken = ( (int) $shared_row['is_broken'] === 1 || 1 === $sub_is_broken ) ? 1 : 0;
				$merged_excluded  = ( (int) $shared_row['excluded'] === 1 || 1 === $sub_excluded ) ? 1 : 0;

				// Merge checks arrays.
				$shared_checks_arr = json_decode( (string) $shared_row['checks'], true );
				$sub_checks_arr    = json_decode( (string) $sub_checks, true );

				if ( ! is_array( $shared_checks_arr ) ) {
					$shared_checks_arr = array();
				}
				if ( ! is_array( $sub_checks_arr ) ) {
					$sub_checks_arr = array();
				}

				$merged_checks = array_merge( $shared_checks_arr, $sub_checks_arr );
				$merged_json   = wp_json_encode( $merged_checks );
				if ( false === $merged_json ) {
					$merged_json = '[]';
				}

				// archived / archive_process / message / redirect_url are NOT touched — first-won.
				$wpdb->update(
					$shared_table,
					array(
						'is_broken' => $merged_is_broken,
						'excluded'  => $merged_excluded,
						'checks'    => $merged_json,
					),
					array( 'id' => $shared_id ),
					array( '%d', '%d', '%s' ),
					array( '%d' )
				);

				$merged_ids[] = $shared_id;
			}

			// Queue a recheck batch for everything we just touched.
			if ( ! empty( $merged_ids ) ) {
				Recheck_Links_Batch_Event::add_to_queue( $merged_ids );
			}
		} finally {
			// Decrement the per-site counter. When it hits 0, drop the subsite table.
			$remaining = (int) get_network_option( 0, $counter_option, 0 );
			$remaining = max( 0, $remaining - 1 );
			update_network_option( 0, $counter_option, $remaining );

			if ( 0 === $remaining ) {
				delete_network_option( 0, $counter_option );
				Migrations::multisite_down( $site_id );
			}
		}
	}
}
