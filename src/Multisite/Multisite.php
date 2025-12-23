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

	/**
	 * Should enable site on multisite?
	 *
	 * @param integer|null $site_id The site ID. Null for current site.
	 *
	 * @return boolean
	 */
	public static function should_enable_site( ?int $site_id = null ): bool {
		// If not multisite, return true.
		if ( ! is_multisite() ) {
			return true;
		}

		$site_id = $site_id ?? get_current_blog_id();

		// Get the available sites.
		$available_sites = Settings::get_multisite_available_sites();

		// If no available sites, return true.
		if ( empty( $available_sites ) ) {
			return true;
		}

		// If the site ID is in the available sites, return true.
		return in_array( $site_id, $available_sites, true );
	}
}
