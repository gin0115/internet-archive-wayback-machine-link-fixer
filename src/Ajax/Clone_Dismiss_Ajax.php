<?php

/**
 * Class which handles the AJAX request for dismissing/clearing clone state.
 *
 * @since   1.4.0
 */

declare( strict_types = 1 );

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Ajax;

use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Table_Clone_State;

defined( 'ABSPATH' ) || exit;

/**
 * Clone_Dismiss_Ajax
 */
class Clone_Dismiss_Ajax {

	/**
	 * The action name for the AJAX request.
	 */
	public const ACTION = 'iawmlf_clone_dismiss';

	/**
	 * The nonce name for the AJAX request.
	 */
	public const NONCE = 'iawmlf_clone_dismiss_nonce';

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

		// Delete the clone state.
		Table_Clone_State::delete();

		wp_send_json_success(
			array(
				'message' => __( 'Clone state cleared.', 'internet-archive-wayback-machine-link-fixer' ),
			)
		);
	}
}
