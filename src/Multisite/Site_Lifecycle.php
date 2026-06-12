<?php

/**
 * Handles site lifecycle events on multisite (site created / site deleted).
 *
 * In separate-tables mode a brand new site needs its own links table, and a
 * deleted site must not leave its links table, merge counter, available-sites
 * entry or clone-state references behind.
 *
 * Registered unconditionally for the whole network (not gated on the current
 * site's availability) because lifecycle events concern other sites.
 *
 * @package Internet_Archive\Wayback_Machine_Link_Fixer\Multisite
 *
 * @since 2.0.0
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Multisite;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Migration\Migrations;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Event\Merge_Duplicate_Links_Batch_Event;

defined( 'ABSPATH' ) || exit;

/**
 * Site Lifecycle handler.
 */
class Site_Lifecycle {

	/**
	 * Register the lifecycle hooks (multisite only).
	 *
	 * @return void
	 */
	public function initialize(): void {
		if ( ! is_multisite() ) {
			return;
		}

		// Priority 100: run after WordPress has fully initialised the new site.
		add_action( 'wp_initialize_site', array( $this, 'on_site_created' ), 100, 1 );
		add_action( 'wp_uninitialize_site', array( $this, 'on_site_deleted' ), 10, 1 );
	}

	/**
	 * Create the links table for a newly created site when running in
	 * separate-tables mode.
	 *
	 * @param \WP_Site $new_site The site being initialised.
	 *
	 * @return void
	 */
	public function on_site_created( \WP_Site $new_site ): void {
		if ( ! Multisite::is_separate_mode() ) {
			return;
		}

		$site_id = (int) $new_site->blog_id;

		// Respect the network's available-sites restriction.
		if ( ! Multisite::should_enable_site( $site_id ) ) {
			return;
		}

		// Run the migrations in the new site's context so the per-blog
		// migration log is written to the right site.
		switch_to_blog( $site_id );
		try {
			Migrations::multisite_up( $site_id );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Clean up after a deleted site: drop its links table and remove every
	 * reference to it (merge counter, available-sites, clone state).
	 *
	 * @param \WP_Site $old_site The site being deleted.
	 *
	 * @return void
	 */
	public function on_site_deleted( \WP_Site $old_site ): void {
		global $wpdb;

		$site_id = (int) $old_site->blog_id;

		// Drop the site's links table directly (whatever the current mode —
		// a leftover table from a previous separate-mode period still needs
		// removing). Migrations::multisite_down() is deliberately not used:
		// its Migration_2 pass can drop the SHARED table when invoked for a
		// subsite in separate mode, and the deleted site's options table
		// (holding its migration log) is going away anyway.
		$subsite_table = Settings::get_subsite_link_table_name( $site_id );
		if ( Settings::get_shared_multisite_link_table_name() !== $subsite_table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `$subsite_table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, table names cant be prepared.
		}

		// Remove the site's pending-merge counter.
		delete_network_option( get_current_network_id(), Merge_Duplicate_Links_Batch_Event::counter_option( $site_id ) );

		// Remove the site from the available-sites restriction.
		$available_sites = Settings::get_multisite_available_sites();
		if ( is_array( $available_sites ) && in_array( $site_id, $available_sites, true ) ) {
			Settings::set_multisite_available_sites(
				array_values( array_diff( $available_sites, array( $site_id ) ) )
			);
		}

		// Remove the site from any persisted clone state.
		$clone_state = Table_Clone_State::load();
		if ( $clone_state ) {
			$to_process = array_values( array_diff( $clone_state->get_sites_to_process(), array( $site_id ) ) );
			$completed  = array_values( array_diff( $clone_state->get_sites_completed(), array( $site_id ) ) );

			if ( $to_process !== $clone_state->get_sites_to_process()
			|| $completed !== $clone_state->get_sites_completed()
			) {
				$clone_state->set_sites_to_process( $to_process );
				$clone_state->set_sites_completed( $completed );
				$clone_state->save();
			}
		}
	}
}
