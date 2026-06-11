<?php

/**
 * Event to migrate links from a shared table to per-site tables.
 *
 * @package Internet_Archive_Wayback_Machine_Link_Fixer\Multisite\Action
 *
 * @since 1.4.0
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Event;

use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Table_Manager;
use Internet_Archive\Wayback_Machine_Link_Fixer\Migration\Migrations;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Table_Clone_State;

use function Symfony\Component\VarDumper\Dumper\esc;

defined( 'ABSPATH' ) || exit;

/**
 * Migrate links from a shared table to per-site tables.
 */
class Migrate_From_Shared_To_Per_Site_Event {

	public const HANDLE = 'iawmlf_migrate_from_shared_to_per_site';

	/**
	 * Access to the table manager.
	 *
	 * @var Table_Manager
	 */
	private $table_manager;

	/**
	 * Current state of the migration.
	 *
	 * @var Table_Clone_State
	 */
	private $current_state;

		/**
	 * Setup the event.
	 *
	 * @return void
	 */
	public function setup(): void {
		$this->table_manager = new Table_Manager();
		$this->current_state = Table_Clone_State::load();
	}

	/**
	 * Adds the event to the queue.
	 *
	 * @param integer[] $sites              The list of site IDs to migrate.
	 * @param boolean   $reset_checks       Whether to reset cloned site checks.
	 * @param boolean   $reset_source_table Whether to reset the source links table.
	 * @param boolean   $share_settings     Whether to share settings across sites.
	 * @param boolean   $force              Whether to force the migration even if not needed.
	 *
	 * @return void
	 */
	public static function add_to_queue( array $sites, bool $reset_checks, bool $reset_source_table, bool $share_settings, bool $force = false ): void {
		$current_state = Table_Clone_State::load();
		if ( false === $force && null !== $current_state && Table_Clone_State::STATUS_RUNNING === $current_state->get_status() ) {
			// Migration already in progress, do not queue again.
			return;
		}

		$current_state = Table_Clone_State::from_global( array_map( 'intval', $sites ) );
		$current_state->set_status( Table_Clone_State::STATUS_RUNNING );
		$current_state->set_reset_checks( $reset_checks );
		$current_state->set_reset_source_table( $reset_source_table );
		$current_state->set_share_settings( $share_settings );
		$current_state->save();

		\as_enqueue_async_action( self::HANDLE, array( 'force' => $force ), 'internet-archive-wayback-machine-link-fixer' );
	}

	/**
	 * Invokes the event.
	 *
	 * @param array $args The event arguments.
	 */
	public function invoke( array $args ): void {
		// Run the setup
		$this->setup();

		// If we do not have a current state, throw an error.
		if ( null === $this->current_state ) {
			throw new \RuntimeException( 'No current state available for migration.' );
		}

		// If the process is completed, exit.
		if ( Table_Clone_State::STATUS_COMPLETED === $this->current_state->get_status() ) {
			return;
		}

		// Find the next table.
		$tables_to_migrate = array_filter(
			$this->current_state->get_sites_to_process(),
			function ( int $site_id ): bool {
				return ! in_array( $site_id, $this->current_state->get_sites_completed(), true );
			}
		);

		// If we have no more tables to migrate, mark as completed.
		if ( empty( $tables_to_migrate ) ) {
			$this->current_state->set_status( Table_Clone_State::STATUS_COMPLETED );
			$this->current_state->save();
			return;
		}

		try {
			$next_site_id = array_shift( $tables_to_migrate );

			// Run the migration for the site.
			$this->table_manager->clone_to_separate_tables(
				$next_site_id,
				$this->current_state->get_reset_checks(),
				empty( $tables_to_migrate ) && $this->current_state->get_reset_source_table()
			);
		} catch ( \Throwable $th ) {
			$this->current_state->set_status( Table_Clone_State::STATUS_ERROR );
			$this->current_state->add_log(
				\sprintf(
				/* translators: %1$d: site ID, %2$s: error message */
					\esc_html__( 'Error migrating site ID %1$d, skipping: %2$s', 'internet-archive-wayback-machine-link-fixer' ),
					$next_site_id,
					$th->getMessage()
				),
				'error'
			);
		} finally {

			// Iterate over the table managers log and add the errors to the current state.
			foreach ( $this->table_manager->get_log() as $log_entry ) {
				$this->current_state->add_log( \esc_html( $log_entry['message'] ), esc_attr( $log_entry['status'] ) );
			}

			// Update the current site as done.
			$this->current_state->add_completed_site( $next_site_id );
			$this->current_state->save();

			// Restore the previous blog context.
			restore_current_blog();

			// Re-queue for the next site if there are remaining sites and no error.
			if ( ! empty( $tables_to_migrate ) && Table_Clone_State::STATUS_ERROR !== $this->current_state->get_status() ) {
				\as_enqueue_async_action( self::HANDLE, array( 'force' => false ), 'internet-archive-wayback-machine-link-fixer' );
			}
		}
	}
}
