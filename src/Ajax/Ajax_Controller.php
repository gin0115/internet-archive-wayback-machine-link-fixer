<?php

/**
 * Handles the regisration of all AJAX actions.
 *
 * @since 1.2.0
 */

declare( strict_types = 1 );

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Ajax;

use Internet_Archive\Wayback_Machine_Link_Fixer\Ajax\Post_Search_Ajax;
use Internet_Archive\Wayback_Machine_Link_Fixer\Ajax\Clone_Start_Ajax;
use Internet_Archive\Wayback_Machine_Link_Fixer\Ajax\Clone_Process_Site_Ajax;
use Internet_Archive\Wayback_Machine_Link_Fixer\Ajax\Clone_Dismiss_Ajax;

defined( 'ABSPATH' ) || exit;

/**
 * Ajax_Controller
 */
class Ajax_Controller {

	/**
	 * Initializes the AJAX actions.
	 *
	 * @return void
	 */
	public function initialize(): void {
		Post_Search_Ajax::register_ajax_call();
		Clone_Start_Ajax::register_ajax_call();
		Clone_Process_Site_Ajax::register_ajax_call();
		Clone_Dismiss_Ajax::register_ajax_call();
	}
}
