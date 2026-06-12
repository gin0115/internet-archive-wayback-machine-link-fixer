<?php

/**
 * Class which handles the AJAX requests for processing a single site clone operation.
 *
 * @since   1.4.0
 */

declare( strict_types = 1 );

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Ajax;

use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Table_Manager;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Table_Clone_State;
use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Clone_Process_Site_Ajax
 */
class Clone_Process_Site_Ajax {

	/**
	 * The action name for the AJAX request.
	 */
	public const ACTION = 'iawmlf_clone_process_site';

	/**
	 * The nonce name for the AJAX request.
	 */
	public const NONCE = 'iawmlf_clone_process_site_nonce';

	/**
	 * Register the ajax action.
	 *
	 * @return void
	 */
	public static function register_ajax_call(): void {
		$handler = static function () {
			( new self() )->__invoke();
		};

		add_action( 'wp_ajax_' . self::ACTION, $handler );
	}

	/**
	 * The invocation method for the AJAX request.
	 *
	 * @return void
	 */
	public function __invoke(): void {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::NONCE ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid security token.', 'internet-archive-wayback-machine-link-fixer' ) ),
				403
			);
		}

		// Check user capability.
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to perform this action.', 'internet-archive-wayback-machine-link-fixer' ) ),
				403
			);
		}

		// Extract site ID.
		$site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
		if ( 0 === $site_id ) {
			wp_send_json_error(
				array( 'message' => __( 'No site ID provided.', 'internet-archive-wayback-machine-link-fixer' ) ),
				400
			);
		}

		// Extract boolean options.
		$reset_checks = isset( $_POST['reset_checks'] ) && '1' === $_POST['reset_checks'];
		$reset_source = isset( $_POST['reset_source'] ) && '1' === $_POST['reset_source'];

		// Load clone state to decide which direction we're running.
		$clone_state = Table_Clone_State::load();
		$clone_type  = $clone_state ? $clone_state->get_clone_type() : Table_Clone_State::TYPE_FROM_GLOBAL_TO_PER_SITE;

		// Process the clone for this single site.
		$table_manager = new Table_Manager();

		try {
			if ( Table_Clone_State::TYPE_FROM_PER_SITE_TO_GLOBAL === $clone_type ) {
				$table_manager->migrate_to_shared_table( $site_id );
				$target_mode = 'shared';
			} else {
				$table_manager->clone_to_separate_tables( $site_id, $reset_checks, $reset_source );
				$target_mode = 'separate';
			}

			// Update the clone state to mark this site as completed.
			if ( $clone_state ) {
				$clone_state->add_completed_site( $site_id );

				// Copy log entries from table manager to state.
				foreach ( $table_manager->get_log() as $log_entry ) {
					$clone_state->add_log( \esc_html( $log_entry['message'] ), \esc_attr( $log_entry['status'] ) );
				}

				// Check if all sites are now completed.
				$all_done = count( $clone_state->get_sites_completed() ) >= count( $clone_state->get_sites_to_process() );
				if ( $all_done ) {
					$clone_state->set_status( Table_Clone_State::STATUS_COMPLETED );

					// Switch the multisite mode now that all sites are done.
					Settings::set_multisite_links_table_mode( $target_mode );
				}

				$clone_state->save();
			}
		} catch ( \Throwable $th ) {
			// Update state with error log (reuse state loaded above).
			if ( $clone_state ) {
				foreach ( $table_manager->get_log() as $log_entry ) {
					$clone_state->add_log( \esc_html( $log_entry['message'] ), \esc_attr( $log_entry['status'] ) );
				}
				$clone_state->add_log(
					\sprintf(
						/* translators: %1$d: site ID, %2$s: error message */
						\esc_html__( 'Error processing site ID %1$d: %2$s', 'internet-archive-wayback-machine-link-fixer' ),
						$site_id,
						$th->getMessage()
					),
					'error'
				);
				$clone_state->save();
			}

			wp_send_json_error(
				array(
					'site_id' => $site_id,
					'message' => $th->getMessage(),
					'log'     => $table_manager->get_log(),
				)
			);
		}

		// Success response is sent outside the try block so a die() converted
		// to an exception (as in the AJAX test framework) is never re-caught
		// and double-responded by the error path above.
		wp_send_json_success(
			array(
				'site_id' => $site_id,
				'log'     => $table_manager->get_log(),
			)
		);
	}
}
