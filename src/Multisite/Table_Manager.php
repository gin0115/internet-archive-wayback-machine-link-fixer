<?php

/**
 * Table Manager for Multisite
 *
 * @package Internet_Archive_Wayback_Machine_Link_Fixer\Util
 *
 * @since 1.4.0
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Multisite;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Migration\Migrations;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Event\Merge_Duplicate_Links_Batch_Event;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Table_Manager
 */
class Table_Manager {

	/**
	 * Current mode.
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Log.
	 *
	 * @var array<int, array{status: string, message: string}>
	 */
	private $log = array();

	/**
	 * Migration Manager.
	 *
	 * @var Migrations
	 */
	private $migrations;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->mode       = Settings::get_multisite_links_table_mode();
		$this->migrations = new Migrations();
	}

	/**
	 * Add to the log.
	 *
	 * @param string $message The log message.
	 * @param string $status  The log status.
	 *
	 * @return void
	 */
	private function add_log( string $message, string $status = 'info' ): void {
		$this->log[] = array(
			'status'  => \esc_attr( $status ),
			'message' => \esc_html( $message ),
		);
	}

	/**
	 * Clone from a shared table to separate tables.
	 *
	 * @param integer $site_id                  The site ID to clone to. If empty, it will not clone to any sites.
	 * @param boolean $clear_cloned_site_checks Whether to clear the cloned site checks.
	 * @param boolean $reset_main_table         Whether to reset the main shared table.
	 *
	 * @return void
	 */
	public function clone_to_separate_tables( int $site_id, bool $clear_cloned_site_checks, bool $reset_main_table ): void {
		// Check the site exists.
		$site_details = \get_blog_details( $site_id );
		if ( false === $site_details ) {
			$this->add_log(
			/* translators: %d: site ID */
				\sprintf( \esc_html__( 'Site with ID %d does not exist. Skipping.', 'internet-archive-wayback-machine-link-fixer' ), $site_id ),
				'error'
			);
			throw new \Exception(
				\esc_html(
					\sprintf( /* translators: %d: site ID */
						\esc_html__( 'Site with ID %d does not exist.', 'internet-archive-wayback-machine-link-fixer' ),
						$site_id
					)
				)
			);
		}
		// Switch to the site.
		switch_to_blog( $site_id );

		// Attempt to run the migrations for the site.
		$this->migrations->multisite_up( $site_id );

		// Empty the table.
		$this->reset_site_links_table( $site_id );

		global $wpdb;
		$shared_table_name  = Settings::get_shared_multisite_link_table_name();
		$subsite_table_name = Settings::get_subsite_link_table_name( $site_id );

		// Clone the data from the shared table to the subsite table.
		$wpdb->query(
			"INSERT INTO `$subsite_table_name` SELECT * FROM `$shared_table_name`" //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// If an error occurred, log it.
		if ( $wpdb->last_error ) {
			$this->add_log(
				/* translators: %s: error message */
				\sprintf( \esc_html__( 'Error cloning links to site %1$s: %2$s', 'internet-archive-wayback-machine-link-fixer' ), $site_details->blogname, $wpdb->last_error ),
				'error'
			);
			restore_current_blog();
			throw new \Exception(
				\esc_html(
					\sprintf( /* translators: %s: error message */
						\esc_html__( 'Error cloning links to site %1$s: %2$s', 'internet-archive-wayback-machine-link-fixer' ),
						$site_details->blogname,
						$wpdb->last_error
					)
				)
			);
		}

		$this->add_log(
			/* translators: %s: site name */
			\sprintf( \esc_html__( 'Cloned links to site %s.', 'internet-archive-wayback-machine-link-fixer' ), $site_details->blogname ),
			'success'
		);

			// If we are to clear the cloned site checks.
		if ( $clear_cloned_site_checks ) {
			$wpdb->query(
				"UPDATE `$subsite_table_name` SET checks = '[]', is_broken = 0" //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			// Add to the log.
			$this->add_log(
				/* translators: %s: site name */
				\sprintf( \esc_html__( 'Reset link checks for site %s.', 'internet-archive-wayback-machine-link-fixer' ), $site_details->blogname ),
				'success'
			);
		}

		if ( $reset_main_table ) {
			// Reset the main table.
			$this->reset_site_links_table( 0 );

			$this->add_log(
				\esc_html__( 'Reset shared links table after cloning.', 'internet-archive-wayback-machine-link-fixer' ),
				'success'
			);
		}
	}

	/**
	 * Migrate one subsite's links into the shared table.
	 *
	 * Inserts non-duplicate rows directly. Duplicate rows (same URL already in
	 * shared) are chunked and queued as AS merge tasks. The subsite's own links
	 * table is dropped only after the last merge task for that site completes
	 * (via the per-site counter in Merge_Duplicate_Links_Batch_Event). If there
	 * are zero duplicates, the subsite table is dropped immediately here.
	 *
	 * @param integer $site_id The source site ID to migrate into the shared table.
	 *
	 * @return void
	 */
	public function migrate_to_shared_table( int $site_id ): void {
		// Check the site exists.
		$site_details = \get_blog_details( $site_id );
		if ( false === $site_details ) {
			$this->add_log(
				/* translators: %d: site ID */
				\sprintf( \esc_html__( 'Site with ID %d does not exist. Skipping.', 'internet-archive-wayback-machine-link-fixer' ), $site_id ),
				'error'
			);
			throw new \Exception(
				\esc_html(
					\sprintf(
						/* translators: %d: site ID */
						\esc_html__( 'Site with ID %d does not exist.', 'internet-archive-wayback-machine-link-fixer' ),
						$site_id
					)
				)
			);
		}

		global $wpdb;

		$shared_table_name  = Settings::get_shared_multisite_link_table_name();
		$subsite_table_name = Settings::get_subsite_link_table_name( $site_id );

		// Ensure the subsite table exists; if not, nothing to do for this site.
		$subsite_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $subsite_table_name )
		);
		if ( $subsite_exists !== $subsite_table_name ) {
			$this->add_log(
				/* translators: %s: site name */
				\sprintf( \esc_html__( 'Subsite links table missing for %s. Skipping.', 'internet-archive-wayback-machine-link-fixer' ), $site_details->blogname ),
				'info'
			);
			return;
		}

		// Insert non-duplicate rows straight into the shared table.
		$inserted = $wpdb->query(
			"INSERT INTO `$shared_table_name` (url, archived, is_broken, checks, message, redirect_url, excluded, archive_process) " //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			. "SELECT s.url, s.archived, s.is_broken, s.checks, s.message, s.redirect_url, s.excluded, s.archive_process "
			. "FROM `$subsite_table_name` s "
			. "LEFT JOIN `$shared_table_name` sh ON sh.url = s.url "
			. 'WHERE sh.id IS NULL'
		);

		if ( $wpdb->last_error ) {
			$this->add_log(
				/* translators: %1$s: site name, %2$s: error message */
				\sprintf( \esc_html__( 'Error inserting links from %1$s to shared table: %2$s', 'internet-archive-wayback-machine-link-fixer' ), $site_details->blogname, $wpdb->last_error ),
				'error'
			);
			throw new \Exception(
				\esc_html(
					\sprintf(
						/* translators: %1$s: site name, %2$s: error message */
						\esc_html__( 'Error inserting links from %1$s to shared table: %2$s', 'internet-archive-wayback-machine-link-fixer' ),
						$site_details->blogname,
						$wpdb->last_error
					)
				)
			);
		}

		$this->add_log(
			/* translators: %1$d: row count, %2$s: site name */
			\sprintf( \esc_html__( 'Inserted %1$d new links from %2$s into shared table.', 'internet-archive-wayback-machine-link-fixer' ), (int) $inserted, $site_details->blogname ),
			'success'
		);

		// Collect the duplicates — rows present in subsite whose URL already exists in shared.
		$duplicates = $wpdb->get_results(
			"SELECT s.url, s.checks, s.is_broken, s.excluded " //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			. "FROM `$subsite_table_name` s "
			. "INNER JOIN `$shared_table_name` sh ON sh.url = s.url",
			ARRAY_A
		);

		if ( empty( $duplicates ) ) {
			// No duplicates — drop the subsite table immediately.
			Migrations::multisite_down( $site_id );
			$this->add_log(
				/* translators: %s: site name */
				\sprintf( \esc_html__( 'No duplicates found for %s; subsite table dropped.', 'internet-archive-wayback-machine-link-fixer' ), $site_details->blogname ),
				'success'
			);
			return;
		}

		// Chunk duplicates and queue merge tasks. Persist per-site counter so the
		// LAST task to finish drops the subsite table.
		$chunks       = array_chunk( $duplicates, 50 );
		$chunk_count  = count( $chunks );

		\update_network_option( 0, Merge_Duplicate_Links_Batch_Event::counter_option( $site_id ), $chunk_count );

		foreach ( $chunks as $chunk ) {
			Merge_Duplicate_Links_Batch_Event::add_to_queue( $site_id, $chunk );
		}

		$this->add_log(
			\sprintf(
				/* translators: %1$d: duplicate row count, %2$d: chunk count, %3$s: site name */
				\esc_html__( 'Queued %1$d duplicate links across %2$d merge tasks for %3$s.', 'internet-archive-wayback-machine-link-fixer' ),
				count( $duplicates ),
				$chunk_count,
				$site_details->blogname
			),
			'info'
		);
	}


	/**
	 * Resets a sites links table.
	 *
	 * @param integer $site_id The site ID. If you pass 0, it will reset the shared table.
	 *
	 * @return void
	 */
	public function reset_site_links_table( int $site_id ): void {
		global $wpdb;

		// Get the table name for the site, or the shared table.
		$site_id    = absint( $site_id );
		$table_name = 0 === $site_id
			? Settings::get_shared_multisite_link_table_name()
			: Settings::get_subsite_link_table_name( $site_id );

		// Check if the table exists.
		if ( $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		) === $table_name ) {
			// Truncate the table.
			$wpdb->query( "TRUNCATE TABLE `$table_name`" );

			if ( 0 === $site_id ) {
				$this->add_log(
					\esc_html__( 'Reset shared links table.', 'internet-archive-wayback-machine-link-fixer' ),
					'success'
				);
			} else {
				$site_details = \get_blog_details( $site_id );
				$site_name    = $site_details->blogname ?? \sprintf(
					/* translators: %d: site ID */
					\esc_html__( 'Site %d', 'internet-archive-wayback-machine-link-fixer' ),
					$site_id
				);
				$this->add_log(
					/* translators: %1$s: site name, %2$d: site ID */
					\sprintf( \esc_html__( 'Reset links table for %1$s (ID: %2$d).', 'internet-archive-wayback-machine-link-fixer' ), $site_name, $site_id ),
					'success'
				);
			}
		}
	}

	/**
	 * Get the log entries.
	 *
	 * @return array<string>
	 */
	public function get_log(): array {
		return $this->log;
	}
}
