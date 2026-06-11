<?php

/**
 * Class which handles the AJAX requests for starting a table clone operation.
 *
 * @since   1.4.0
 */

declare( strict_types = 1 );

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Ajax;

use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Table_Clone_State;
// use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Event\Migrate_From_Shared_To_Per_Site_Event;

defined( 'ABSPATH' ) || exit;

/**
 * Clone_Start_Ajax
 */
class Clone_Start_Ajax {

	/**
	 * The action name for the AJAX request.
	 */
	public const ACTION = 'iawmlf_clone_start';

	/**
	 * The nonce name for the AJAX request.
	 */
	public const NONCE = 'iawmlf_clone_start_nonce';

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

		// Extract and sanitize sites (comes as JSON string from JS).
		$sites_raw = isset( $_POST['sites'] ) ? sanitize_text_field( wp_unslash( $_POST['sites'] ) ) : '[]';
		$sites     = json_decode( $sites_raw, true );
		$sites     = is_array( $sites ) ? array_map( 'intval', $sites ) : array();

		// If we have no sites, return error.
		if ( empty( $sites ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No sites selected for cloning.', 'internet-archive-wayback-machine-link-fixer' ) ),
				400
			);
		}

		// Extract new mode — decides direction of the clone.
		$new_mode = isset( $_POST['new_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['new_mode'] ) ) : '';
		if ( ! in_array( $new_mode, array( 'shared', 'separate' ), true ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid target mode.', 'internet-archive-wayback-machine-link-fixer' ) ),
				400
			);
		}

		// Extract boolean options (with defaults).
		$reset_checks       = isset( $_POST['reset_checks'] ) && '1' === $_POST['reset_checks'];
		$reset_source_table = isset( $_POST['reset_source'] ) && '1' === $_POST['reset_source'];
		$share_settings     = ! isset( $_POST['share_settings'] ) || '1' === $_POST['share_settings'];

		// Build the clone state based on direction.
		// new_mode = separate → shared-to-per-site (from_global)
		// new_mode = shared   → per-site-to-shared (to_global)
		$site_ids    = array_map( 'intval', $sites );
		$clone_state = 'shared' === $new_mode
			? Table_Clone_State::to_global( $site_ids )
			: Table_Clone_State::from_global( $site_ids );

		$clone_state->set_status( Table_Clone_State::STATUS_RUNNING );
		$clone_state->set_reset_checks( $reset_checks );
		$clone_state->set_reset_source_table( $reset_source_table );
		$clone_state->set_share_settings( $share_settings );
		$clone_state->save();

		wp_send_json_success(
			array(
				'message' => __( 'Clone process initialized. Processing sites...', 'internet-archive-wayback-machine-link-fixer' ),
				'sites'   => $sites,
			)
		);
	}
}
