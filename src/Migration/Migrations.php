<?php

/**
 * The Database Migration Service.
 *
 * @since 0.1.0
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Migration;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The migration class.
 */
class Migrations {

	/**
	 * The list of all migrations that should be run.
	 *
	 * @since 0.1.0
	 * @var class-string<Abstract_Migration>[]
	 */
	public static $migrations = array();

	/**
	 * Run the migrations on plugin activation.
	 *
	 * @since 0.1.0
	 *
	 * @param boolean $force Can be forced to run the migrations.
	 *
	 * @return void
	 */
	public static function up( bool $force = false ): void {
		// Get previous migrations.
		$previously_run_migrations = Settings::migrations();

		foreach ( self::$migrations as $migration ) {
			// If the migration has already been run, skip it.
			if ( true === $force
			|| ! in_array( $migration, $previously_run_migrations, true )
			) {
				( new $migration() )->up();

				// Add the migration to the list of migrations that have been run.
				$previously_run_migrations[] = $migration;
			}
		}

		// Update the list of migrations that have been run.
		Settings::update_migrations( $previously_run_migrations );
	}

	/**
	 * Multisite Up.
	 *
	 * The migration log is always read from and written to the TARGET site's
	 * own options (explicitly switched), regardless of the caller's context.
	 *
	 * @param integer $site_id The site ID.
	 * @param boolean $force   Run the migrations even if the site's log claims
	 *                         they already ran (self-heal for stale logs).
	 *
	 * @return void
	 */
	public static function multisite_up( int $site_id, bool $force = false ): void {
		// Read the target site's own migration log.
		switch_to_blog( $site_id );
		$previously_run_migrations = Settings::migrations( true );
		restore_current_blog();

		foreach ( self::$migrations as $migration ) {
			// If the migration has already been run, skip it (unless forced).
			if ( true === $force || ! in_array( $migration, $previously_run_migrations, true ) ) {
				( new $migration() )->up( Settings::get_subsite_link_table_name( $site_id ) );
				// Add the migration to the list of migrations that have been run.
				if ( ! in_array( $migration, $previously_run_migrations, true ) ) {
					$previously_run_migrations[] = $migration;
				}
			}
		}

		// Write the log back to the target site.
		switch_to_blog( $site_id );
		Settings::update_migrations( $previously_run_migrations, true );
		restore_current_blog();
	}

	/**
	 * Run the migrations on plugin uninstall.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function down(): void {
		// Get previous migrations.
		$previously_run_migrations = Settings::migrations();

		foreach ( array_reverse( self::$migrations ) as $migration ) {
			( new $migration() )->down();

			// Remove the migration from the list of migrations that have been run.
			$previously_run_migrations = array_diff( $previously_run_migrations, array( $migration ) );
		}

		// Update the list of migrations that have been run.
		Settings::update_migrations( $previously_run_migrations );
	}

	/**
	 * Multisite Down.
	 *
	 * The migration log is always read from and written to the TARGET site's
	 * own options (explicitly switched), regardless of the caller's context.
	 * Previously it used the CURRENT blog's log, which left the target site's
	 * log claiming migrations had run after its table was dropped — making
	 * the next multisite_up() skip table creation.
	 *
	 * @param integer $site_id The site ID.
	 *
	 * @return void
	 */
	public static function multisite_down( int $site_id ): void {
		// Read the target site's own migration log.
		switch_to_blog( $site_id );
		$previously_run_migrations = Settings::migrations( true );
		restore_current_blog();

		foreach ( array_reverse( self::$migrations ) as $migration ) {
			( new $migration() )->down( Settings::get_subsite_link_table_name( $site_id ) );
			// Remove the migration from the list of migrations that have been run.
			$previously_run_migrations = array_diff( $previously_run_migrations, array( $migration ) );
		}

		// Write the log back to the target site.
		switch_to_blog( $site_id );
		Settings::update_migrations( $previously_run_migrations, true );
		restore_current_blog();
	}
}
