<?php

/**
 * Migration 2
 *
 * Created: 23 Dec 2025
 * Iteration: 2
 *
 * @since 1.4.0
 *
 * This is to allow better multisite support.
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer_Migration;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Migration\Abstract_Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Migration 2
 */
class Migration_2 extends Abstract_Migration {

	/**
	 * Run when the table is created.
	 *
	 * @since 1.4.0
	 *
	 * @param string|null $table_name Optional table name.
	 *
	 * @return void
	 */
	public function up( ?string $table_name = null ): void {
		// If this is a multisite, ensure we create the shared link table.
		if ( is_multisite() ) {
			// Check the table name assumed and the one for the shared links table.
			$assumed_table_name = Settings::get_link_table_name();
			$shared_table_name  = Settings::get_shared_multisite_link_table_name();
			// If the table names are different, rename the assumed table to the shared table name.
			if ( $assumed_table_name !== $shared_table_name
			|| ! $this->shared_link_table_exists() ) {
				$this->create_shared_link_table();
			}
		}
	}

	/**
	 * Creates the shared link table.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	private function create_shared_link_table(): void {
		// Create the shared link table.
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Create the link cache table.
		$shared_link_table_name = Settings::get_shared_multisite_link_table_name();

		$shared_link_table_sql = "CREATE TABLE $shared_link_table_name (
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
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $shared_link_table_sql );
	}

	/**
	 * Checks if the shared link table exists.
	 *
	 * @since 1.4.0
	 *
	 * @return boolean
	 */
	private function shared_link_table_exists(): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Settings::get_shared_multisite_link_table_name() ) ) !== null;
	}

	/**
	 * Drops the shared link table.
	 *
	 * @since 1.4.0
	 *
	 * @param string|null $table_name Optional table name.
	 *
	 * @return void
	 */
	public function down( ?string $table_name = null ): void {
		global $wpdb;

		// If this is a multisite, drop the shared link table.
		if ( is_multisite() ) {
			// Check the table name assumed and the one for the shared links table.
			$assumed_table_name = Settings::get_link_table_name();
			$shared_table_name  = Settings::get_shared_multisite_link_table_name();
			// If the table names are different, drop the shared table.
			if ( $assumed_table_name !== $shared_table_name ) {
				$wpdb->query( 'DROP TABLE IF EXISTS ' . $shared_table_name ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, cant due to table name.
			}
		}
	}
}
