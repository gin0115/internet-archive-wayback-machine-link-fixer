<?php

/**
 * Handles functionality specific to multisite installations.
 *
 * @package Internet_Archive_Wayback_Machine_Link_Fixer\Util
 *
 * @since 1.4.0
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Multisite;


use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Multisite Helper Service
 */
class Multisite {

	/**
	 * Shared links table mode.
	 */
	const SHARED_LINKS_TABLE_MODE = 'shared';

	/**
	 * Separate links table mode.
	 */
	const SEPARATE_LINKS_TABLE_MODE = 'separate';

	/**
	 * Setup the multisite functionality.
	 *
	 * @param boolean $network_wide Whether the plugin is being activated network wide.
	 *
	 * @return void
	 */
	public static function setup( ?bool $network_wide ): void {
		// Bail if null.
		if ( null === $network_wide ) {
			return;
		}

		// Set the multisite links table mode.
		Settings::set_multisite_links_table_mode(
			$network_wide ? self::SHARED_LINKS_TABLE_MODE : self::SEPARATE_LINKS_TABLE_MODE
		);
	}
}
