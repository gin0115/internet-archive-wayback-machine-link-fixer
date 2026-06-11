<?php

/**
 * The Settings access class.
 *
 * @since      1.0.0
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Settings;

use Internet_Archive\Wayback_Machine_Link_Fixer\Util\Environmental;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Multisite;
use Internet_Archive\Wayback_Machine_Link_Fixer\Migration\Abstract_Migration;
use Internet_Archive\Wayback_Machine_Link_Fixer\Event\Check_Archive_Services_Online_Event;

defined( 'ABSPATH' ) || exit;

/**
 * Settings
 */
class Settings {

	// Prefix.
	public const SETTINGS_PREFIX = 'iawmlf_';


	// Option keys
	public const PROCESS_LINKS                  = self::SETTINGS_PREFIX . 'process_links';
	public const ALLOWED_POST_TYPES             = self::SETTINGS_PREFIX . 'post_types';
	public const MIGRATIONS_KEY                 = self::SETTINGS_PREFIX . 'migration_log';
	public const DROP_TABLES_ON_UNINSTALL_KEY   = self::SETTINGS_PREFIX . 'drop_tables_uninstall';
	public const LINK_EXCLUSIONS                = self::SETTINGS_PREFIX . 'link_exclusions';
	public const SCAN_EXISTING_POSTS            = self::SETTINGS_PREFIX . 'scan_existing_posts';
	public const ARCHIVE_ORG_SECRET_KEY         = self::SETTINGS_PREFIX . 'archive_api_secret';
	public const ARCHIVE_ORG_ACCESS_KEY         = self::SETTINGS_PREFIX . 'archive_api_access';
	public const FIXER_OPTION                   = self::SETTINGS_PREFIX . 'fixer_option';
	public const ARCHIVE_ORG_STATUS_KEY         = self::SETTINGS_PREFIX . 'archive_api_status';
	public const ARCHIVE_ORG_CREDS_VALID_KEY    = self::SETTINGS_PREFIX . 'archive_api_creds_valid';
	public const MINIMUM_CHECKS_BEFORE_BROKEN   = self::SETTINGS_PREFIX . 'failed_count';
	public const LINK_CHECK_DURATION_IN_DAYS    = self::SETTINGS_PREFIX . 'link_check_duration_in_days';
	public const POST_ACTIVATION_ONBOARDING_KEY = self::SETTINGS_PREFIX . 'post_activation_onboarding';
	public const SETUP_WIZARD_STEP_KEY          = self::SETTINGS_PREFIX . 'setup_wizard';
	public const SETUP_WIZARD_COMPLETED_KEY     = self::SETTINGS_PREFIX . 'setup_wizard_completed';
	public const ONBOARDING_DATE_KEY            = self::SETTINGS_PREFIX . 'onboarding_date';

	// Table names.
	public const LINK_TABLE = 'iawmlf_link_archive';


	// Meta Keys
	public const LINK_META_KEY           = self::SETTINGS_PREFIX . 'links';
	public const OWN_LINK_LAST_PROCESSED = self::SETTINGS_PREFIX . 'last_processed';

	// Fixer Options
	public const FIXER_OPTION_DO_NOTHING   = 'do_nothing';
	public const FIXER_OPTION_REPLACE_LINK = 'replace_link';

	// Onboarding options.
	public const ONBOARDING_COMPLETED_OPTION = self::SETTINGS_PREFIX . 'onboarding_completed';
	public const ONBOARDING_PENDING_OPTION   = self::SETTINGS_PREFIX . 'onboarding_pending';

	// Own content submissions.
	public const ALLOW_OWN_CONTENT_SUBMISSIONS             = self::SETTINGS_PREFIX . 'allow_own_content_submissions';
	public const ALLOWED_OWN_CONTENT_POST_TYPES            = self::SETTINGS_PREFIX . 'allowed_own_content_post_types';
	public const ROUTINELY_UPDATE_WAYBACK_MACHINE          = self::SETTINGS_PREFIX . 'routinely_update_wayback_machine';
	public const ROUTINELY_UPDATE_WAYBACK_MACHINE_INTERVAL = self::SETTINGS_PREFIX . 'routinely_update_wayback_machine_interval';

	// Multisite options.
	public const MULTISITE_LINKS_TABLE_MODE = self::SETTINGS_PREFIX . 'multisite_links_table_mode';
	public const MULTISITE_AVAILABLE_SITES  = self::SETTINGS_PREFIX . 'multisite_available_sites';
	public const TABLE_CLONE_STATE          = self::SETTINGS_PREFIX . 'table_clone_state';

	/**
	 * Gets the link table name.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public static function get_link_table_name(): string {
		global $wpdb;

		// If not multisite, return the normal table name.
		if ( ! Multisite::is_network_active() ) {
			return $wpdb->prefix . self::LINK_TABLE;
		}

		return Multisite::is_separate_mode()
			? $wpdb->get_blog_prefix( get_current_blog_id() ) . self::LINK_TABLE
			: $wpdb->base_prefix . self::LINK_TABLE;
	}

	/**
	 * Get the multisite shared table name.
	 *
	 * @since 1.4.0
	 *
	 * @return string
	 */
	public static function get_shared_multisite_link_table_name(): string {
		global $wpdb;
		return $wpdb->base_prefix . self::LINK_TABLE;
	}

	/**
	 * Get a sub sites table name.
	 *
	 * @since 1.4.0
	 *
	 * @param integer $site_id The site ID.
	 *
	 * @return string
	 */
	public static function get_subsite_link_table_name( int $site_id ): string {
		global $wpdb;
		return $wpdb->get_blog_prefix( $site_id ) . self::LINK_TABLE;
	}

	/**
	 * Is the link processing enabled?
	 *
	 * @since 1.3.0
	 *
	 * @param boolean $default_value Optional default value if not set. Default false.
	 *
	 * @return boolean
	 */
	public static function is_link_processing_enabled( bool $default_value = false ): bool {
		return (bool) self::get_multisite_aware_option( self::PROCESS_LINKS, $default_value );
	}

	/**
	 * Get all post types which should be scanned.
	 *
	 * @since   1.0.0
	 *
	 * @return  string[]
	 */
	public static function get_allowed_post_types(): array {
		return array_map( 'esc_html', (array) self::get_multisite_aware_option( self::ALLOWED_POST_TYPES, array( 'page', 'post' ) ) );
	}

	/**
	 * Should the tables be dropped when the plugin is deactivated?
	 *
	 * When called from a multisite, it checks the network option, as its always network wide.
	 *
	 * @since 0.1.0
	 *
	 * @return boolean
	 */
	public static function drop_tables_on_uninstall(): bool {
		return Multisite::is_network_active()
			? (bool) get_network_option( null, self::DROP_TABLES_ON_UNINSTALL_KEY, false )
			: (bool) get_option( self::DROP_TABLES_ON_UNINSTALL_KEY, false );
	}

	/**
	 * Get the processed migrations.
	 *
	 * @since 0.1.0
	 *
	 * @param boolean $ignore_multisite_mode Optional ignore multisite mode. Default false.
	 *
	 * @return class-string<Abstract_Migration>[]
	 */
	public static function migrations( bool $ignore_multisite_mode = false ): array {
		if ( $ignore_multisite_mode ) {
			return (array) get_option( self::MIGRATIONS_KEY, array() );
		}

		return (array) self::get_multisite_aware_option( self::MIGRATIONS_KEY, array() );
	}

	/**
	 * Update the migrations
	 *
	 * @since 0.1.0
	 *
	 * @param class-string<Abstract_Migration>[] $migrations            The migrations to update.
	 * @param boolean                            $ignore_multisite_mode Optional ignore multisite mode. Default false.
	 *
	 * @return void
	 */
	public static function update_migrations( array $migrations, bool $ignore_multisite_mode = false ): void {
		if ( $ignore_multisite_mode ) {
			update_option( self::MIGRATIONS_KEY, $migrations, false );
		} else {
			self::update_multisite_aware_option( self::MIGRATIONS_KEY, $migrations );
		}
	}

	/**
	 * Get the link checker timeout in MS
	 *
	 * @since 1.0.0
	 *
	 * @return integer
	 */
	public static function get_link_checker_timeout(): int {
		return absint( apply_filters( 'iawmlf_link_checker_timeout', 5000 ) );
	}

	/**
	 * Get the array of link exclusions.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public static function get_link_exclusions(): array {
		$links = array_map( 'esc_html', (array) self::get_multisite_aware_option( self::LINK_EXCLUSIONS, array() ) );
		return apply_filters( 'iawmlf_link_exclusions', $links );
	}

	/**
	 * Get the number of posts to process per batch.
	 *
	 * @since 1.0.0
	 *
	 * @return integer
	 */
	public static function get_posts_per_batch(): int {
		$per_batch = absint( apply_filters( 'iawmlf_posts_per_batch', 10 ) );

		// If value is less than or equal to 1, set as 2.
		return $per_batch <= 1 ? 2 : $per_batch;
	}

	/**
	 * Get the link check duration.
	 * In days
	 *
	 * @since 1.2.0
	 *
	 * @return integer
	 */
	public static function get_link_check_duration(): int {
		$duration = absint( self::get_multisite_aware_option( self::LINK_CHECK_DURATION_IN_DAYS, 3 ) );
		return absint( apply_filters( 'iawmlf_link_check_duration_in_days', $duration ) );
	}

	/**
	 * Which HTTP Status codes are treated as valid.
	 *
	 * @since 1.2.0
	 *
	 * @return integer[]
	 */
	public static function get_valid_http_status_codes(): array {
		$codes = array( 200, 206, 429 );
		return (array) apply_filters( 'iawmlf_valid_http_status_codes', $codes );
	}

	/**
	 * Should existing posts be scanned?
	 *
	 * @since 1.2.0
	 *
	 * @param boolean $default_value Optional default value if not set. Default false.
	 *
	 * @return boolean
	 */
	public static function should_scan_existing_posts( bool $default_value = false ): bool {
		// If you can not process links, return false.
		if ( ! self::is_link_processing_enabled( $default_value ) ) {
			return false;
		}
		return (bool) self::get_multisite_aware_option( self::SCAN_EXISTING_POSTS, $default_value );
	}

	/**
	 * How many times does a link need to be invalid before considered broken.
	 *
	 * @since 1.2.0
	 *
	 * @return integer
	 */
	public static function get_failed_count(): int {
		$retries = absint( self::get_multisite_aware_option( self::MINIMUM_CHECKS_BEFORE_BROKEN, 3 ) );
		return absint( apply_filters( 'iawmlf_failed_count', $retries ) );
	}

	/**
	 * Get the archive.org API key.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	public static function get_archive_secret_key(): string {
		return esc_attr( get_option( self::ARCHIVE_ORG_SECRET_KEY, '' ) );
	}

	/**
	 * Get the archive.org API secret.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	public static function get_archive_access_key(): string {
		return esc_attr( get_option( self::ARCHIVE_ORG_ACCESS_KEY, '' ) );
	}

	/**
	 * Checks if the archive.org API is configured.
	 *
	 * @since 1.3.0
	 *
	 * @return boolean
	 */
	public static function is_archive_api_configured(): bool {
		return '' !== self::get_archive_secret_key() && '' !== self::get_archive_access_key();
	}

	/**
	 * Gets the current fixer option.
	 *
	 * @since 1.3.0
	 *
	 * @param string $default_value Optional default value if not set. Default FIXER_OPTION_REPLACE_LINK.
	 *
	 * @return string
	 */
	public static function get_fixer_option( string $default_value = self::FIXER_OPTION_REPLACE_LINK ): string {
		return esc_attr( self::get_multisite_aware_option( self::FIXER_OPTION, $default_value ) );
	}

	/**
	 * Checks if the archive.org API is online.
	 *
	 * @since 1.3.0
	 *
	 * @return boolean
	 */
	public static function is_archive_api_online(): bool {
		return iawmlf_is_archive_api_online();
	}

	/**
	 * Get the archive api extended status.
	 *
	 * @since 1.3.0
	 *
	 * @return array|null
	 */
	public static function get_archive_api_status(): ?array {
		$status = get_transient( self::ARCHIVE_ORG_STATUS_KEY );

		// If we dont have a status, trigger a check.
		if ( false === $status ) {
			Check_Archive_Services_Online_Event::add_to_queue();
			return null;
		}

		return is_array( $status ) ? $status : null;
	}

	/**
	 * Checks if posts should be added to wayback machine on save.
	 *
	 * @since 1.3.0
	 *
	 * @param boolean $default_value Optional default value if not set. Default false.
	 *
	 * @return boolean
	 */
	public static function add_own_links( bool $default_value = false ): bool {
		$allow = (bool) self::get_multisite_aware_option( self::ALLOW_OWN_CONTENT_SUBMISSIONS, $default_value );

		// If not production, force false.
		if ( ! Environmental::is_production() ) {
			$allow = false;
		}

		return (bool) apply_filters(
			'iawmlf_add_own_content_to_wayback_machine',
			$allow
		);
	}

	/**
	 * Gets the post types whos posts should be added to the wayback machine.
	 *
	 * @since 1.3.0
	 *
	 * @return string[]
	 */
	public static function own_link_allowed_post_types(): array {
		return apply_filters(
			'iawmlf_own_content_post_types',
			array_map( 'esc_html', (array) self::get_multisite_aware_option( self::ALLOWED_OWN_CONTENT_POST_TYPES, array( 'post', 'page' ) ) )
		);
	}

	/**
	 * Checks if posts should be routinely updated in the wayback machine.
	 *
	 * @since 1.3.0
	 *
	 * @param boolean $default_value Optional default value if not set. Default false.
	 *
	 * @return boolean
	 */
	public static function own_link_routinely_update( bool $default_value = false ): bool {
		return (bool) apply_filters(
			'iawmlf_routinely_update_wayback_machine',
			(bool) self::get_multisite_aware_option( self::ROUTINELY_UPDATE_WAYBACK_MACHINE, $default_value )
		);
	}

	/**
	 * Gets the interval between updates.
	 *
	 * @since 1.3.0
	 *
	 * @return integer Time in days.
	 */
	public static function own_link_routine_update_interval(): int {
		$default  = 28;
		$interval = absint(
			apply_filters(
				'iawmlf_routinely_update_wayback_machine_interval',
				self::get_multisite_aware_option( self::ROUTINELY_UPDATE_WAYBACK_MACHINE_INTERVAL, $default )
			)
		);

		return $interval <= 0 ? $default : $interval;
	}

	/**
	 * Should the link table, show additional data?
	 *
	 * This is for debugging purposes only.
	 *
	 * @since 1.3.0
	 *
	 * @return boolean
	 */
	public static function show_link_table_debug_data(): bool {
		return (bool) apply_filters( 'iawmlf_show_link_table_debug_data', false );
	}

	/**
	 * Check if we have valid credentials for the archive.org API.
	 *
	 * @since 1.3.0
	 *
	 * @return boolean
	 */
	public static function has_valid_archive_api_credentials(): bool {
		return (bool) get_option( self::ARCHIVE_ORG_CREDS_VALID_KEY, false ) === true;
	}

	/**
	 * Updates the archive.org API credentials validity.
	 *
	 * @since 1.3.0
	 *
	 * @param boolean $valid True if the credentials are valid, false otherwise.
	 *
	 * @return void
	 */
	public static function update_archive_api_credentials_validity( bool $valid ): void {
		update_option( self::ARCHIVE_ORG_CREDS_VALID_KEY, $valid, false );
	}

	/**
	 * Gets the required capability for the reporting page.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	public static function get_reporting_page_capability(): string {
		return apply_filters(
			'iawmlf_reporting_page_capability',
			'manage_options'
		);
	}

	/**
	 * Checks if the HTML link output should be rendered in the frontend.
	 *
	 * @since 1.3.1
	 *
	 * @return boolean
	 */
	public static function should_render_html_link_output(): bool {
		$allowed = in_array( self::get_fixer_option(), array( self::FIXER_OPTION_REPLACE_LINK ), true );

		/**
		 * Filter to allow or disallow the HTML link output in the frontend.
		 *
		 * @since 1.3.1
		 *
		 * @param boolean $allowed Whether the HTML link output should be rendered.
		 * @param string  $option  The current fixer option.
		 *
		 * @return boolean
		 */
		return (bool) apply_filters( 'iawmlf_should_render_html_link_output', $allowed, self::get_fixer_option() );
	}

	/**
	 * Get the current Wizard step
	 *
	 * @since 1.3.4
	 *
	 * @param string $default_value Optional default value if not set. Default 'step-1'.
	 *
	 * @return string
	 */
	public static function get_setup_wizard_step( string $default_value = 'step-1' ): string {
		return (string) self::get_multisite_aware_option( self::SETUP_WIZARD_STEP_KEY, $default_value );
	}

	/**
	 * Sets the current Wizard step
	 *
	 * @since 1.3.4
	 *
	 * @param string $step The step to set.
	 *
	 * @return void
	 */
	public static function update_setup_wizard_step( string $step ): void {
		self::update_multisite_aware_option( self::SETUP_WIZARD_STEP_KEY, $step );
	}

	/**
	 * Checks if the wizard has been completed.
	 *
	 * @since 1.3.4
	 *
	 * @return boolean
	 */
	public static function is_wizard_completed(): bool {
		return (bool) self::get_multisite_aware_option( self::SETUP_WIZARD_COMPLETED_KEY, false );
	}

	/**
	 * Mark the wizard as completed or not.
	 *
	 * @since 1.3.4
	 *
	 * @param boolean $completed True if completed, false otherwise.
	 *
	 * @return void
	 */
	public static function set_wizard_completed( bool $completed ): void {
		self::update_multisite_aware_option( self::SETUP_WIZARD_COMPLETED_KEY, $completed );
	}

	/**
	 * Gets the onboarding date.
	 *
	 * @since 1.3.4
	 *
	 * @return string|null
	 */
	public static function get_onboarding_date(): ?string {
		$date = self::get_multisite_aware_option( self::ONBOARDING_DATE_KEY, null );
		return is_string( $date ) ? $date : null;
	}

	/**
	 * Sets the onboarding date.
	 *
	 * @since 1.3.4
	 *
	 * @param string $date The date to set.
	 *
	 * @return void
	 */
	public static function set_onboarding_date( string $date ): void {
		self::update_multisite_aware_option( self::ONBOARDING_DATE_KEY, $date );
	}

	/**
	 * Sets the onboarding status.
	 *
	 * @since 1.3.4
	 *
	 * @param string $status The status to set.
	 *
	 * @return void
	 */
	public static function set_onboarding_status( string $status ): void {
		// If the passed status is not valid, set as pending.
		if ( ! in_array( $status, array( self::ONBOARDING_COMPLETED_OPTION, self::ONBOARDING_PENDING_OPTION ), true ) ) {
			$status = self::ONBOARDING_PENDING_OPTION;
		}

		self::update_multisite_aware_option( self::POST_ACTIVATION_ONBOARDING_KEY, $status, false );
	}

	/**
	 * Get the current onboarding status.
	 *
	 * @since 1.3.4
	 *
	 * @param string $default_value Optional default value if not set. Default ONBOARDING_PENDING_OPTION.
	 *
	 * @return string
	 */
	public static function get_onboarding_status( string $default_value = self::ONBOARDING_COMPLETED_OPTION ): string {
		$state = self::get_multisite_aware_option( self::POST_ACTIVATION_ONBOARDING_KEY, $default_value );
		return in_array( $state, array( self::ONBOARDING_COMPLETED_OPTION, self::ONBOARDING_PENDING_OPTION ), true )
			? $state
			: self::ONBOARDING_PENDING_OPTION;
	}

	/**
	 * Sets the multisite links table mode.
	 *
	 * @since 1.4.0
	 *
	 * @param 'shared'|'separate' $mode The mode to set.
	 *
	 * @return void
	 */
	public static function set_multisite_links_table_mode( string $mode ): void {
		if ( ! in_array( $mode, array( Multisite::SHARED_LINKS_TABLE_MODE, Multisite::SEPARATE_LINKS_TABLE_MODE ), true ) ) {
			$mode = Multisite::SHARED_LINKS_TABLE_MODE;
		}

		if ( Multisite::is_network_active() ) {
			update_network_option( get_current_network_id(), self::MULTISITE_LINKS_TABLE_MODE, $mode );
		} else {
			update_option( self::MULTISITE_LINKS_TABLE_MODE, $mode );
		}
	}

	/**
	 * Gets the multisite links table mode.
	 *
	 * @since 1.4.0
	 *
	 * @return 'shared'|'separate'
	 */
	public static function get_multisite_links_table_mode(): string {
		$default = Multisite::SHARED_LINKS_TABLE_MODE;
		$mode    = Multisite::is_network_active()
			? get_network_option( get_current_network_id(), self::MULTISITE_LINKS_TABLE_MODE, $default )
			: get_option( self::MULTISITE_LINKS_TABLE_MODE, $default );

		return in_array( $mode, array( Multisite::SHARED_LINKS_TABLE_MODE, Multisite::SEPARATE_LINKS_TABLE_MODE ), true )
			? $mode
			: $default;
	}

	/**
	 * Gets the sites where the plugin is available.
	 *
	 * @since 1.4.0
	 *
	 * @return array|null Null = all sites, empty array = no sites, array of IDs = specific sites
	 */
	public static function get_multisite_available_sites(): ?array {
		if ( ! Multisite::is_network_active() ) {
			return null;
		}

		$sites = get_network_option( get_current_network_id(), self::MULTISITE_AVAILABLE_SITES, null );
		return is_array( $sites ) ? $sites : null;
	}

	/**
	 * Sets the sites where the plugin is available.
	 *
	 * @since 1.4.0
	 *
	 * @param array|null $sites Array of site IDs, null for all sites.
	 *
	 * @return void
	 */
	public static function set_multisite_available_sites( ?array $sites ): void {
		if ( ! Multisite::is_network_active() ) {
			return;
		}

		$value = is_array( $sites ) ? array_map( 'absint', $sites ) : null;
		update_network_option( get_current_network_id(), self::MULTISITE_AVAILABLE_SITES, $value );
	}

	/**
	 * Gets an option with multisite mode awareness.
	 *
	 * @since 1.4.0
	 *
	 * @param string $key           The option key.
	 * @param mixed  $default_value The default value if not set.
	 *
	 * @return mixed
	 */
	private static function get_multisite_aware_option( string $key, $default_value = false ) {
		// On multisite, settings are always stored at network level regardless of links table mode.
		return Multisite::is_network_active()
			? get_network_option( get_current_network_id(), $key, $default_value )
			: get_option( $key, $default_value );
	}

	/**
	 * Updates an option with multisite mode awareness.
	 *
	 * @since 1.4.0
	 *
	 * @param string $key      The option key.
	 * @param mixed  $value    The value to set.
	 * @param mixed  $autoload Whether to autoload the option.
	 *
	 * @return boolean
	 */
	private static function update_multisite_aware_option( string $key, $value, $autoload = null ) {
		// On multisite, settings are always stored at network level regardless of links table mode.
		return Multisite::is_network_active()
			? update_network_option( get_current_network_id(), $key, $value )
			: update_option( $key, $value, $autoload );
	}

	/**
	 * Deletes an option with multisite mode awareness.
	 *
	 * @since 1.4.0
	 *
	 * @param string $key The option key.
	 *
	 * @return boolean
	 */
	private static function delete_multisite_aware_option( string $key ) {
		// On multisite, settings are always stored at network level regardless of links table mode.
		return Multisite::is_network_active()
			? delete_network_option( get_current_network_id(), $key )
			: delete_option( $key );
	}

	/**
	 * Clear all the options.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public static function clear_all_options(): void {
		// Clear all the options.
		delete_option( self::PROCESS_LINKS );
		delete_option( self::ALLOWED_POST_TYPES );
		delete_option( self::MIGRATIONS_KEY );
		delete_option( self::DROP_TABLES_ON_UNINSTALL_KEY );
		delete_option( self::LINK_EXCLUSIONS );
		delete_option( self::SCAN_EXISTING_POSTS );
		delete_option( self::ARCHIVE_ORG_SECRET_KEY );
		delete_option( self::ARCHIVE_ORG_ACCESS_KEY );
		delete_option( self::FIXER_OPTION );
		delete_option( self::ARCHIVE_ORG_STATUS_KEY );
		delete_option( self::ARCHIVE_ORG_CREDS_VALID_KEY );
		delete_option( self::ALLOW_OWN_CONTENT_SUBMISSIONS );
		delete_option( self::ALLOWED_OWN_CONTENT_POST_TYPES );
		delete_option( self::ROUTINELY_UPDATE_WAYBACK_MACHINE );
		delete_option( self::ROUTINELY_UPDATE_WAYBACK_MACHINE_INTERVAL );
		delete_option( self::POST_ACTIVATION_ONBOARDING_KEY );
		delete_option( self::MINIMUM_CHECKS_BEFORE_BROKEN );
		delete_option( self::LINK_CHECK_DURATION_IN_DAYS );
		delete_option( self::SETUP_WIZARD_STEP_KEY );
		delete_option( self::SETUP_WIZARD_COMPLETED_KEY );
	}
}
