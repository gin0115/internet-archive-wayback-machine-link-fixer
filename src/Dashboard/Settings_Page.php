<?php

/**
 * The Settings page controller class.
 *
 * @since      1.0.0
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Dashboard;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Util\Environmental;
use Internet_Archive\Wayback_Machine_Link_Fixer\Dashboard\Setup_Wizard;
use Internet_Archive\Wayback_Machine_Link_Fixer\Dashboard\Dashboard_Page;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Multisite;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Table_Clone_State;

defined( 'ABSPATH' ) || exit;

/**
 * Settings Page
 */
class Settings_Page {

	public const PAGE_SLUG             = 'iawmlf_settings';
	public const SETTINGS_SECTION      = 'iawmlf-settings';
	public const GROUP_IA_SETTINGS     = 'iawmlf_ia_settings';
	public const GROUP_PLUGIN_SETTINGS = 'iawmlf_plugin_settings';
	public const GROUP_LINK_FIXER      = 'iawmlf_group';
	public const GROUP_AUTO_ARCHIVER   = 'iawmlf_auto_archiver';

	/**
	 * The pages menu hook.
	 *
	 * @since   1.0.0
	 * @var string|false
	 */
	private $menu_hook = false;

	/**
	 * Initialise the settings page.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function initialize(): void {
		add_action( 'admin_init', array( $this, 'register_fields' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_menu', array( $this, 'register_page' ), 20, 0 );
		add_action( 'admin_init', array( $this, 'validate_archive_org_keys' ), 1 );
		add_action( 'wp_ajax_iawmlf_dismiss_donation_cta', array( $this, 'dismiss_donation_cta' ) );

		if ( Multisite::is_network_active() ) {
			add_action( 'network_admin_menu', array( $this, 'register_network_page' ), 20 );
			add_action( 'network_admin_edit_iawmlf_net_settings_save', array( $this, 'handle_network_settings_save' ) );
		}
	}

	/**
	 * Get the settings page URL.
	 *
	 * @since   1.3.0
	 *
	 * @return  string
	 */
	public static function get_page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Register the settings page.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function register_fields(): void {
		// Register the settings fields.
		$this->register_settings_fields();
		$this->add_settings_fields();
	}


	/**
	 * Registers the settings page.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function register_page(): void {
		$this->menu_hook = add_submenu_page(
			Dashboard_Page::DASHBOARD_SLUG,
			__( 'Wayback Link Fixer Settings', 'internet-archive-wayback-machine-link-fixer' ),
			__( 'Advanced Settings', 'internet-archive-wayback-machine-link-fixer' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		// If the setup has not been completed, redirect to wizard (not on multisite sub-sites — network handles it).
		if ( ! Settings::is_wizard_completed() && ! Multisite::is_network_active() ) {
			add_action( 'load-' . $this->menu_hook, array( $this, 'redirect_to_setup_wizard' ) );
		}
	}

	/**
	 * Registers the network admin settings page.
	 *
	 * @return void
	 */
	public function register_network_page(): void {
		$this->menu_hook = add_submenu_page(
			Dashboard_Page::DASHBOARD_SLUG,
			__( 'Wayback Link Fixer Settings', 'internet-archive-wayback-machine-link-fixer' ),
			__( 'Advanced Settings', 'internet-archive-wayback-machine-link-fixer' ),
			'manage_network_options',
			self::PAGE_SLUG,
			array( $this, 'render_network_page' )
		);

		// If the setup has not been completed, add a on load hook.
		if ( ! Settings::is_wizard_completed() ) {
			add_action( 'load-' . $this->menu_hook, array( $this, 'redirect_to_setup_wizard' ) );
		}
	}

	/**
	 * Renders the network admin settings page.
	 *
	 * @return void
	 */
	public function render_network_page(): void {
		iawmlf_render_not_authenticated_notice();

		// Check if the wizard has been completed.
		$wizard_link = '';
		if ( Settings::is_wizard_completed() ) {
			$wizard_link = \sprintf(
				'<a href="%s" class="button button-primary">%s</a>',
				esc_url( Setup_Wizard::get_wizard_url() . '&rerun-wizard=1' ),
				esc_html__( 'Rerun The Setup Wizard', 'internet-archive-wayback-machine-link-fixer' )
			);
		}

		echo '<div class="wrap">';
		printf(
			'<div id="iawmlf_settings_header" class="iawmlf-settings__header"><h1 class="wp-heading-inline iawmlf-settings__header">%s</h1></div>',
			esc_html__( 'Wayback Link Fixer - Advanced Settings', 'internet-archive-wayback-machine-link-fixer' )
		);

		echo '<hr class="wp-header-end"><form action="edit.php?action=iawmlf_net_settings_save" method="post">';

		do_settings_sections( self::PAGE_SLUG );
		settings_fields( self::PAGE_SLUG );

		submit_button( __( 'Save Changes', 'internet-archive-wayback-machine-link-fixer' ) );

		echo '</form></div>';
	}

	/**
	 * Handles the network admin settings save.
	 *
	 * @return void
	 */
	public function handle_network_settings_save(): void {
		// Check user capability.
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'internet-archive-wayback-machine-link-fixer' ) );
		}

		// Verify nonce.
		check_admin_referer( self::PAGE_SLUG . '-options' );

		// Define all settings with their types.
		$settings = array(
			Settings::PROCESS_LINKS                    => 'boolean',
			Settings::DROP_TABLES_ON_UNINSTALL_KEY     => 'boolean',
			Settings::SCAN_EXISTING_POSTS              => 'boolean',
			Settings::ALLOW_OWN_CONTENT_SUBMISSIONS    => 'boolean',
			Settings::ROUTINELY_UPDATE_WAYBACK_MACHINE => 'boolean',
			Settings::ALLOWED_POST_TYPES               => 'array',
			Settings::LINK_EXCLUSIONS                  => 'array',
			Settings::ALLOWED_OWN_CONTENT_POST_TYPES   => 'array',
			Settings::MINIMUM_CHECKS_BEFORE_BROKEN     => 'integer',
			Settings::LINK_CHECK_DURATION_IN_DAYS      => 'integer',
			Settings::ROUTINELY_UPDATE_WAYBACK_MACHINE_INTERVAL => 'integer',
			Settings::ARCHIVE_ORG_SECRET_KEY           => 'string',
			Settings::ARCHIVE_ORG_ACCESS_KEY           => 'string',
			Settings::FIXER_OPTION                     => 'string',
			Settings::MULTISITE_LINKS_TABLE_MODE       => 'string',
			Settings::MULTISITE_AVAILABLE_SITES        => 'array_nullable',
		);

		// Track if API keys were updated.
		$api_keys_updated = false;

		// Process each setting based on its type.
		foreach ( $settings as $key => $type ) {
			switch ( $type ) {
				case 'boolean':
					$value = isset( $_POST[ $key ] ) ? true : false; // phpcs:ignore WordPress.Security.NonceVerification.Missing
					update_network_option( get_current_network_id(), $key, $value );
					break;

				case 'array':
					$value = isset( $_POST[ $key ] ) ? array_map( 'sanitize_text_field', (array) $_POST[ $key ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
					update_network_option( get_current_network_id(), $key, $value );
					break;

				case 'integer':
					if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
						$value = absint( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

						// Apply minimum value logic.
						if ( Settings::MINIMUM_CHECKS_BEFORE_BROKEN === $key ) {
							$value = 0 === $value ? 5 : $value;
						} elseif ( Settings::LINK_CHECK_DURATION_IN_DAYS === $key ) {
							$value = 0 === $value ? 7 : $value;
						} elseif ( Settings::ROUTINELY_UPDATE_WAYBACK_MACHINE_INTERVAL === $key ) {
							$value = 0 === $value ? 28 : $value;
						}

						update_network_option( get_current_network_id(), $key, $value );
					}
					break;

				case 'string':
					if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
						$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

						// Track if API keys are being updated.
						if ( Settings::ARCHIVE_ORG_SECRET_KEY === $key || Settings::ARCHIVE_ORG_ACCESS_KEY === $key ) {
							$api_keys_updated = true;
						}

						update_network_option( get_current_network_id(), $key, $value );
					}
					break;

				case 'array_nullable':
					// Special handling: null if not set, array if set.
					if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
						$value = array_map( 'absint', (array) $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
						update_network_option( get_current_network_id(), $key, $value );
					} else {
						// Not present in POST = treat as null (all sites).
						update_network_option( get_current_network_id(), $key, null );
					}
					break;
			}
		}

		// Validate API keys if they were updated.
		if ( $api_keys_updated ) {
			$key           = get_network_option( get_current_network_id(), Settings::ARCHIVE_ORG_SECRET_KEY, '' );
			$access_key    = get_network_option( get_current_network_id(), Settings::ARCHIVE_ORG_ACCESS_KEY, '' );
			$system_client = iawmlf_get_system_client();
			$is_valid      = $system_client->is_valid_user( $access_key, $key );

			update_option( Settings::ARCHIVE_ORG_CREDS_VALID_KEY, $is_valid, false );
		}

		// Redirect back to the settings page.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => 'true',
				),
				network_admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Redirect to setup wizard if API keys are invalid.
	 *
	 * @since   1.3.1
	 *
	 * @return  void
	 */
	public function redirect_to_setup_wizard(): void {
		wp_safe_redirect( Setup_Wizard::get_wizard_url() );
		exit;
	}

	/**
	 * AJAX handler to dismiss the donation CTA.
	 *
	 * @return void
	 */
	public function dismiss_donation_cta(): void {
		check_ajax_referer( 'iawmlf_dismiss_donation_cta' );
		update_user_meta( get_current_user_id(), 'iawmlf_dismiss_donation_cta', true );
		wp_send_json_success();
	}

	/**
	 * Enqueue the settings page scripts.
	 *
	 * @since   1.0.0
	 *
	 * @param   string $page_hook The page hook.
	 *
	 * @return  void
	 */
	public function enqueue_scripts( string $page_hook ): void {
		// Only enqueue the scripts on the settings page.
		if ( $this->menu_hook !== $page_hook ) {
			return;
		}

		wp_register_script(
			self::PAGE_SLUG,
			IAWMLF_URL . 'assets/js/build/admin_settings.js',
			array( 'jquery', 'wp-escape-html' ),
			IAWMLF_VERSION,
			true
		);

		wp_localize_script(
			self::PAGE_SLUG,
			'IawmlfSettings',
			array(
				'newExcludedTemplate'             => $this->render_excluded_url( '{newUrl}', '{newIndex}' ),
				'newExcludedPostTemplate'         => $this->render_excluded_post_template(),
				'newExcludedArchiverPostTemplate' => $this->render_excluded_archiver_post_template(),
				'environment'                     => Environmental::is_production() ? 'production' : 'development',
				'ajaxUrl'                         => admin_url( 'admin-ajax.php' ),
				'dismissDonationCtaNonce'         => wp_create_nonce( 'iawmlf_dismiss_donation_cta' ),
				'postSearchNonce'                 => wp_create_nonce( 'iawmlf_post_search' ),
				'linkIconStyles'                  => array_column( Settings::get_available_link_icons(), 'css_rule', 'id' ),
			)
		);

		wp_enqueue_script( self::PAGE_SLUG );

		// Conditionally enqueue multisite clone script.
		if ( Multisite::is_network_active() ) {
			wp_register_script(
				'iawmlf-multisite-clone',
				IAWMLF_URL . 'assets/js/build/admin_multisite_clone.js',
				array( self::PAGE_SLUG ),
				IAWMLF_VERSION,
				true
			);

			wp_localize_script(
				'iawmlf-multisite-clone',
				'IawmlfCloneSettings',
				array(
					'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
					'cloneStartAction'       => \Internet_Archive\Wayback_Machine_Link_Fixer\Ajax\Clone_Start_Ajax::ACTION,
					'cloneStartNonce'        => wp_create_nonce( \Internet_Archive\Wayback_Machine_Link_Fixer\Ajax\Clone_Start_Ajax::NONCE ),
					'cloneProcessSiteAction' => \Internet_Archive\Wayback_Machine_Link_Fixer\Ajax\Clone_Process_Site_Ajax::ACTION,
					'cloneProcessSiteNonce'  => wp_create_nonce( \Internet_Archive\Wayback_Machine_Link_Fixer\Ajax\Clone_Process_Site_Ajax::NONCE ),
					'cloneDismissAction'     => \Internet_Archive\Wayback_Machine_Link_Fixer\Ajax\Clone_Dismiss_Ajax::ACTION,
					'cloneDismissNonce'      => wp_create_nonce( \Internet_Archive\Wayback_Machine_Link_Fixer\Ajax\Clone_Dismiss_Ajax::NONCE ),
					'multisiteSites'         => $this->get_multisite_sites_for_js(),
				)
			);

			wp_localize_script(
				'iawmlf-multisite-clone',
				'IawmlfCloneTemplates',
				array(
					/* translators: %1$d: number of skipped sites */
					'skippedHeading'  => __( 'Skipped %1$d site(s) due to errors:', 'internet-archive-wayback-machine-link-fixer' ),
					/* translators: %1$s: site ID, %2$s: error message */
					'skippedSiteItem' => __( 'Site %1$s: %2$s', 'internet-archive-wayback-machine-link-fixer' ),
					'completedLabel'  => __( 'Completed', 'internet-archive-wayback-machine-link-fixer' ),
					'completedErrors' => __( 'Completed with errors', 'internet-archive-wayback-machine-link-fixer' ),
					/* translators: %1$d: completed count, %2$d: total count */
					'finishedLog'     => __( 'Clone process finished. %1$d of %2$d sites completed.', 'internet-archive-wayback-machine-link-fixer' ),
					/* translators: %1$d: number of remaining sites */
					'resumingLog'     => __( 'Resuming migration — %1$d site(s) remaining.', 'internet-archive-wayback-machine-link-fixer' ),
					/* translators: %1$s: site ID */
					'retryingLog'     => __( 'Retrying site %1$s...', 'internet-archive-wayback-machine-link-fixer' ),
					/* translators: %1$s: site ID, %2$s: error message */
					'skippingLog'     => __( 'Skipping site %1$s after retry: %2$s', 'internet-archive-wayback-machine-link-fixer' ),
					/* translators: %1$s: site ID */
					'requestFailed'   => __( 'Request failed for site %1$s, retrying...', 'internet-archive-wayback-machine-link-fixer' ),
					/* translators: %1$d: completed sites, %2$d: total sites, %3$s: percentage */
					'progressText'    => __( '%1$d of %2$d sites completed (%3$s%%)', 'internet-archive-wayback-machine-link-fixer' ),
				)
			);

			wp_enqueue_script( 'iawmlf-multisite-clone' );
		}

		//  Register the styles.
		wp_enqueue_style(
			self::PAGE_SLUG,
			IAWMLF_URL . 'assets/css/build/style-style.scss.css',
			array(),
			IAWMLF_VERSION
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @since   1.0.0
	 *
	 * @return  void
	 */
	public function render_page(): void {
		iawmlf_render_not_authenticated_notice();

		// Check if the wizard has been completed.
		$wizard_link = '';
		if ( Settings::is_wizard_completed() ) {
			$wizard_link = \sprintf(
				'<a href="%s" class="button button-primary">%s</a>',
				esc_url( Setup_Wizard::get_wizard_url() . '&rerun-wizard=1' ),
				esc_html__( 'Rerun The Setup Wizard', 'internet-archive-wayback-machine-link-fixer' )
			);
		}

		echo '<div class="wrap">';
		printf(
			'<div id="iawmlf_settings_header" class="iawmlf-settings__header"><h1 class="wp-heading-inline iawmlf-settings__header">%s</h1></div>',
			esc_html__( 'Wayback Link Fixer - Advanced Settings', 'internet-archive-wayback-machine-link-fixer' )
		);

		echo '<hr class="wp-header-end">';

		if ( ! get_user_meta( get_current_user_id(), 'iawmlf_dismiss_donation_cta', true ) ) {
			printf(
				'<div class="iawmlf_donation_cta" id="iawmlf_donation_cta"><img src="%s" alt="%s" class="iawmlf_donation_cta__logo" /><p>%s</p><a href="%s" target="_blank" class="button button-primary iawmlf_donation_cta__button">%s</a><button type="button" class="notice-dismiss"><span class="screen-reader-text">%s</span></button></div>',
				esc_url( IAWMLF_URL . 'assets/images/ia-logo.svg' ),
				esc_attr__( 'Internet Archive', 'internet-archive-wayback-machine-link-fixer' ),
				esc_html__( 'This plugin is powered by the Internet Archive. If you find the plugin useful, please chip in! Your support will help us build the web we deserve.', 'internet-archive-wayback-machine-link-fixer' ),
				esc_url( 'https://archive.org/donate/?origin=wdps-wbmlf' ),
				esc_html__( 'Donate', 'internet-archive-wayback-machine-link-fixer' ),
				esc_attr__( 'Dismiss', 'internet-archive-wayback-machine-link-fixer' )
			);
		}

		echo '<form action="options.php" method="post">';

		do_settings_sections( self::PAGE_SLUG );
		settings_fields( self::PAGE_SLUG );

		// Only show submit button if there are editable fields (not on multisite sub-sites).
		if ( ! Multisite::is_network_active() ) {
			submit_button( __( 'Save Changes', 'internet-archive-wayback-machine-link-fixer' ) );
		}

		echo '</form></div>';
	}

	/**
	 * Registers the settings fields.
	 *
	 * @since   1.0.0
	 *
	 * @return  void
	 */
	private function register_settings_fields(): void {
		register_setting(
			self::PAGE_SLUG,
			Settings::PROCESS_LINKS,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'wp_validate_boolean',
				'default'           => false,
				'show_in_rest'      => array(
					'name'   => Settings::PROCESS_LINKS,
					'schema' => array(
						'type' => 'boolean',
					),
				),
			)
		);

		register_setting(
			self::PAGE_SLUG,
			Settings::ALLOWED_POST_TYPES,
			array(
				'type'              => 'array',
				'sanitize_callback' => fn( $value ): array => array_map( 'sanitize_text_field', (array) $value ),
				'default'           => array( 'page', 'post' ),
				'show_in_rest'      => array(
					'name'   => Settings::ALLOWED_POST_TYPES,
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'string',
						),
					),
				),
			)
		);
		// Only register in network admin or non-multisite.
		if ( ! Multisite::is_subsite() ) {
			register_setting(
				self::PAGE_SLUG,
				Settings::DROP_TABLES_ON_UNINSTALL_KEY,
				array(
					'type'              => 'boolean',
					'sanitize_callback' => 'wp_validate_boolean',
					'default'           => false,
					'show_in_rest'      => array(
						'name'   => Settings::DROP_TABLES_ON_UNINSTALL_KEY,
						'schema' => array(
							'type' => 'boolean',
						),
					),
				)
			);
		}

		register_setting(
			self::PAGE_SLUG,
			Settings::SCAN_EXISTING_POSTS,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'wp_validate_boolean',
				'default'           => false,
				'show_in_rest'      => array(
					'name'   => Settings::SCAN_EXISTING_POSTS,
					'schema' => array(
						'type' => 'boolean',
					),
				),
			)
		);

		register_setting(
			self::PAGE_SLUG,
			Settings::LINK_EXCLUSIONS,
			array(
				'type'              => 'array',
				'sanitize_callback' => fn( $value ): array => array_map( 'sanitize_text_field', (array) $value ),
				'default'           => array(),
				'show_in_rest'      => array(
					'name'   => Settings::LINK_EXCLUSIONS,
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'string',
						),
					),
				),
			)
		);

		register_setting(
			self::PAGE_SLUG,
			Settings::LINK_FIXER_EXCLUDED_POSTS,
			array(
				'type'              => 'array',
				'sanitize_callback' => fn( $value ): array => array_map( 'absint', (array) $value ),
				'default'           => array(),
				'show_in_rest'      => array(
					'name'   => Settings::LINK_FIXER_EXCLUDED_POSTS,
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'integer',
						),
					),
				),
			)
		);

		register_setting(
			self::PAGE_SLUG,
			Settings::MINIMUM_CHECKS_BEFORE_BROKEN,
			array(
				'type'              => 'integer',
				'sanitize_callback' => function ( $value ) {
					$value = absint( $value );
					return 0 === $value ? 5 : $value;
				},
				'default'           => 5,
				'show_in_rest'      => array(
					'name'   => Settings::MINIMUM_CHECKS_BEFORE_BROKEN,
					'schema' => array(
						'type' => 'integer',
					),
				),
			)
		);

		\register_setting(
			self::PAGE_SLUG,
			Settings::LINK_CHECK_DURATION_IN_DAYS,
			array(
				'type'              => 'integer',
				'sanitize_callback' => function ( $value ) {
					$value = absint( $value );
					return 0 === $value ? 7 : $value;
				},
				'default'           => 7,
				'show_in_rest'      => array(
					'name'   => Settings::LINK_CHECK_DURATION_IN_DAYS,
					'schema' => array(
						'type' => 'integer',
					),
				),
			)
		);

		// Only register in network admin or non-multisite.
		if ( ! Multisite::is_subsite() ) {
			register_setting(
				self::PAGE_SLUG,
				Settings::ARCHIVE_ORG_SECRET_KEY,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
					'show_in_rest'      => false,
				),
			);

			register_setting(
				self::PAGE_SLUG,
				Settings::ARCHIVE_ORG_ACCESS_KEY,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
					'show_in_rest'      => false,
				)
			);
		}

		register_setting(
			self::PAGE_SLUG,
			Settings::FIXER_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Settings::FIXER_OPTION_REPLACE_LINK,
				'show_in_rest'      => false,
			)
		);

		register_setting(
			self::PAGE_SLUG,
			Settings::CAST_ARCHIVED_TO_HTTPS,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'wp_validate_boolean',
				'default'           => false,
				'show_in_rest'      => array(
					'name'   => Settings::CAST_ARCHIVED_TO_HTTPS,
					'schema' => array(
						'type' => 'boolean',
					),
				),
			)
		);

		register_setting(
			self::PAGE_SLUG,
			Settings::ALLOW_OWN_CONTENT_SUBMISSIONS,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'wp_validate_boolean',
				'default'           => false,
				'show_in_rest'      => array(
					'name'   => Settings::ALLOW_OWN_CONTENT_SUBMISSIONS,
					'schema' => array(
						'type' => 'boolean',
					),
				),
			)
		);

		register_setting(
			self::PAGE_SLUG,
			Settings::ROUTINELY_UPDATE_WAYBACK_MACHINE,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'wp_validate_boolean',
				'default'           => false,
				'show_in_rest'      => array(
					'name'   => Settings::ROUTINELY_UPDATE_WAYBACK_MACHINE,
					'schema' => array(
						'type' => 'boolean',
					),
				),
			)
		);

		register_setting(
			self::PAGE_SLUG,
			Settings::ALLOWED_OWN_CONTENT_POST_TYPES,
			array(
				'type'              => 'array',
				'sanitize_callback' => fn( $value ): array => array_map( 'sanitize_text_field', (array) $value ),
				'default'           => array( 'page', 'post' ),
				'show_in_rest'      => array(
					'name'   => Settings::ALLOWED_OWN_CONTENT_POST_TYPES,
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'string',
						),
					),
				),
			)
		);

		register_setting(
			self::PAGE_SLUG,
			Settings::ROUTINELY_UPDATE_WAYBACK_MACHINE_INTERVAL,
			array(
				'type'              => 'integer',
				'sanitize_callback' => function ( $value ) {
					$value = absint( $value );
					return 0 === $value ? 28 : $value;
				},
				'default'           => 28,
				'show_in_rest'      => array(
					'name'   => Settings::ROUTINELY_UPDATE_WAYBACK_MACHINE_INTERVAL,
					'schema' => array(
						'type' => 'integer',
					),
				),
			)
		);

		register_setting(
			self::PAGE_SLUG,
			Settings::AUTO_ARCHIVER_EXCLUDED_POSTS,
			array(
				'type'              => 'array',
				'sanitize_callback' => fn( $value ): array => array_map( 'absint', (array) $value ),
				'default'           => array(),
				'show_in_rest'      => array(
					'name'   => Settings::AUTO_ARCHIVER_EXCLUDED_POSTS,
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'integer',
						),
					),
				),
			)
		);

		register_setting(
			self::PAGE_SLUG,
			Settings::LINK_ICON,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Settings::LINK_ICON_NONE,
				'show_in_rest'      => array(
					'name'   => Settings::LINK_ICON,
					'schema' => array(
						'type' => 'string',
					),
				),
			)
		);

		// Register multisite mode setting (network-wide only).
		if ( Multisite::is_network_active() ) {
			register_setting(
				self::PAGE_SLUG,
				Settings::MULTISITE_LINKS_TABLE_MODE,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => Multisite::SHARED_LINKS_TABLE_MODE,
					'show_in_rest'      => false,
				)
			);

			register_setting(
				self::PAGE_SLUG,
				Settings::MULTISITE_AVAILABLE_SITES,
				array(
					'type'              => 'array',
					'sanitize_callback' => function ( $value ) {
						// null = all sites, [] = no sites, array = specific sites.
						if ( null === $value || '' === $value ) {
							return null;
						}
						return is_array( $value ) ? array_map( 'absint', $value ) : null;
					},
					'default'           => null,
					'show_in_rest'      => false,
				)
			);
		}
	}

	/**
	 * Add all settings fields.
	 *
	 * @since   1.0.0
	 *
	 * @return  void
	 */
	private function add_settings_fields(): void {
		add_settings_section(
			self::GROUP_PLUGIN_SETTINGS,
			__( 'Plugin Settings', 'internet-archive-wayback-machine-link-fixer' ),
			'__return_empty_string',
			self::PAGE_SLUG,
			array(
				'before_section' => '<div id="iawmlf_settings_plugin_section" class="iawmlf_settings_postbox">',
				'after_section'  => '</div>',
			)
		);

		// Only show Archive.org API section in network admin or non-multisite.
		if ( ! Multisite::is_subsite() ) {
			add_settings_section(
				self::GROUP_IA_SETTINGS,
				__( 'Archive.org API', 'internet-archive-wayback-machine-link-fixer' ),
				function () {
					printf(
						/* translators: 1: Opening sentence, 2: URL to the Internet Archive S3 keys page, 3: Closing sentence. */
						'<p class="description">%1$s <a href="%2$s" target="_blank">%3$s</a></p>',
						esc_html__( 'To increase your daily snapshot limit from 4,000 to 30,000, you can enter your free Archive.org API credentials. Visit', 'internet-archive-wayback-machine-link-fixer' ),
						esc_url( 'https://archive.org/account/s3.php' ),
						esc_html__( 'archive.org/account/s3.php to generate your Access Key and Secret Key.', 'internet-archive-wayback-machine-link-fixer' )
					);
				},
				self::PAGE_SLUG,
				array(
					'before_section' => '<div id="iawmlf_settings_ia_section" class="iawmlf_settings_postbox">',
					'after_section'  => $this->render_invalid_api_keys_message() . '</div>',
				)
			);
		}

		add_settings_section(
			self::GROUP_LINK_FIXER,
			__( 'Link Fixer', 'internet-archive-wayback-machine-link-fixer' ),
			array( $this, 'render_link_fixer_section' ),
			self::PAGE_SLUG,
			array(
				'before_section' => '<div id="iawmlf_settings_link_fixer_section" class="iawmlf_settings_postbox">',
				'after_section'  => '</div>',
			)
		);

		add_settings_section(
			self::GROUP_AUTO_ARCHIVER,
			__( 'Auto Archiver', 'internet-archive-wayback-machine-link-fixer' ),
			array( $this, 'render_auto_archiver_section' ),
			self::PAGE_SLUG,
			array(
				'before_section' => sprintf( '<div id="iawmlf_settings_link_fixer_section" class="iawmlf_settings_postbox auto-archiver %s">', ! Environmental::is_production() ? 'staging' : '' ),
				'after_section'  => '</div>',
			)
		);

		// Only register Link Fixer fields on single site or network admin. Sub-sites always get read-only.
		if ( ! Multisite::is_subsite() ) {
			add_settings_field(
				Settings::PROCESS_LINKS,
				__( 'Enable Link Fixer', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_process_links_field' ),
				self::PAGE_SLUG,
				self::GROUP_LINK_FIXER
			);

			add_settings_field(
				Settings::ALLOWED_POST_TYPES,
				__( 'Post Types', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_fixer_post_types_field' ),
				self::PAGE_SLUG,
				self::GROUP_LINK_FIXER,
				array( 'class' => Settings::is_link_processing_enabled() ? 'iawmlf_toggle_setting__fixer' : 'iawmlf_toggle_setting__fixer hidden' )
			);

			add_settings_field(
				Settings::SCAN_EXISTING_POSTS,
				__( 'Existing Posts', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_check_existing_posts' ),
				self::PAGE_SLUG,
				self::GROUP_LINK_FIXER,
				array( 'class' => Settings::is_link_processing_enabled() ? 'iawmlf_toggle_setting__fixer' : 'iawmlf_toggle_setting__fixer hidden' )
			);

			add_settings_field(
				Settings::FIXER_OPTION,
				__( 'Fixer Option', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_fixer_option' ),
				self::PAGE_SLUG,
				self::GROUP_LINK_FIXER,
				array( 'class' => Settings::is_link_processing_enabled() ? 'iawmlf_toggle_setting__fixer' : 'iawmlf_toggle_setting__fixer hidden' )
			);

			$link_icon_visible = Settings::is_link_processing_enabled() && Settings::FIXER_OPTION_REPLACE_LINK === Settings::get_fixer_option();
			add_settings_field(
				Settings::LINK_ICON,
				__( 'Link Icon', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_link_icon_field' ),
				self::PAGE_SLUG,
				self::GROUP_LINK_FIXER,
				array( 'class' => 'iawmlf_toggle_setting__fixer iawmlf_toggle_setting__fixer_replace' . ( $link_icon_visible ? '' : ' hidden' ) )
			);

			add_settings_field(
				Settings::LINK_EXCLUSIONS,
				__( 'Link Exclusions', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_link_exclusions_field' ),
				self::PAGE_SLUG,
				self::GROUP_LINK_FIXER,
				array( 'class' => Settings::is_link_processing_enabled() ? 'iawmlf_toggle_setting__fixer' : 'iawmlf_toggle_setting__fixer hidden' )
			);

			add_settings_field(
				Settings::LINK_FIXER_EXCLUDED_POSTS,
				__( 'Post Exclusions', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_post_exclusions_field' ),
				self::PAGE_SLUG,
				self::GROUP_LINK_FIXER,
				array( 'class' => Settings::is_link_processing_enabled() ? 'iawmlf_toggle_setting__fixer' : 'iawmlf_toggle_setting__fixer hidden' )
			);

			add_settings_field(
				Settings::LINK_CHECK_DURATION_IN_DAYS,
				__( 'Check Frequency', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_link_check_duration_field' ),
				self::PAGE_SLUG,
				self::GROUP_LINK_FIXER,
				array( 'class' => Settings::is_link_processing_enabled() ? 'iawmlf_toggle_setting__fixer' : 'iawmlf_toggle_setting__fixer hidden' )
			);

			add_settings_field(
				Settings::MINIMUM_CHECKS_BEFORE_BROKEN,
				__( 'Failure Threshold', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_minimum_checks_before_broken_field' ),
				self::PAGE_SLUG,
				self::GROUP_LINK_FIXER,
				array( 'class' => Settings::is_link_processing_enabled() ? 'iawmlf_toggle_setting__fixer' : 'iawmlf_toggle_setting__fixer hidden' )
			);

			add_settings_field(
				Settings::CAST_ARCHIVED_TO_HTTPS,
				__( 'Cast Archived Links to HTTPS', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_cast_archived_to_https_field' ),
				self::PAGE_SLUG,
				self::GROUP_LINK_FIXER,
				array( 'class' => Settings::is_link_processing_enabled() ? 'iawmlf_toggle_setting__fixer' : 'iawmlf_toggle_setting__fixer hidden' )
			);
		}

		// Only show in network admin or non-multisite.
		if ( ! Multisite::is_subsite() ) {
			add_settings_field(
				Settings::DROP_TABLES_ON_UNINSTALL_KEY,
				__( 'Wipe Data on Uninstall', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_drop_tables_on_uninstall_field' ),
				self::PAGE_SLUG,
				self::GROUP_PLUGIN_SETTINGS
			);
		}

		// Add multisite mode field (only in multisite installations).
		if ( Multisite::is_network_active() ) {
			add_settings_field(
				Settings::MULTISITE_LINKS_TABLE_MODE,
				__( 'Multisite Mode', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_multisite_mode_field' ),
				self::PAGE_SLUG,
				self::GROUP_PLUGIN_SETTINGS
			);

			// Add available sites field (only in network admin).
			if ( Multisite::is_network() ) {
				add_settings_field(
					Settings::MULTISITE_AVAILABLE_SITES,
					__( 'Available on Sites', 'internet-archive-wayback-machine-link-fixer' ),
					array( $this, 'render_multisite_available_sites_field' ),
					self::PAGE_SLUG,
					self::GROUP_PLUGIN_SETTINGS
				);
			}
		}

		// Only show in network admin or non-multisite.
		if ( ! Multisite::is_subsite() ) {
			$empty_api_creds = '' === Settings::get_archive_access_key() && '' === Settings::get_archive_secret_key();

			add_settings_field(
				Settings::ARCHIVE_ORG_ACCESS_KEY,
				__( 'Archive.org Access Key', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_archive_api_access_key' ),
				self::PAGE_SLUG,
				self::GROUP_IA_SETTINGS,
				array(
					'class' => Settings::has_valid_archive_api_credentials() || $empty_api_creds ? '' : 'iawmlf_toggle_setting__invalid_api_keys',
				)
			);

			add_settings_field(
				Settings::ARCHIVE_ORG_SECRET_KEY,
				__( 'Archive.org Secret Key', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_archive_api_secret_key' ),
				self::PAGE_SLUG,
				self::GROUP_IA_SETTINGS,
				array(
					'class' => Settings::has_valid_archive_api_credentials() || $empty_api_creds ? '' : 'iawmlf_toggle_setting__invalid_api_keys',
				)
			);
		}

		// Only register Auto Archiver fields on single site or network admin. Sub-sites always get read-only.
		if ( ! Multisite::is_subsite() ) {
			add_settings_field(
				Settings::ALLOW_OWN_CONTENT_SUBMISSIONS,
				__( 'Auto Archive Posts', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_allow_own_posts' ),
				self::PAGE_SLUG,
				self::GROUP_AUTO_ARCHIVER
			);

			add_settings_field(
				Settings::ROUTINELY_UPDATE_WAYBACK_MACHINE,
				__( 'Routinely Archive', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_own_link_routinely_update' ),
				self::PAGE_SLUG,
				self::GROUP_AUTO_ARCHIVER,
				array( 'class' => Settings::add_own_links() ? 'iawmlf_toggle_setting__auto_archiver' : 'iawmlf_toggle_setting__auto_archiver hidden' )
			);

			add_settings_field(
				Settings::ROUTINELY_UPDATE_WAYBACK_MACHINE_INTERVAL,
				__( 'Routine Interval', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_own_link_routinely_update_interval' ),
				self::PAGE_SLUG,
				self::GROUP_AUTO_ARCHIVER,
				array( 'class' => Settings::add_own_links() ? 'iawmlf_toggle_setting__auto_archiver' : 'iawmlf_toggle_setting__auto_archiver hidden' )
			);

			add_settings_field(
				Settings::ALLOWED_OWN_CONTENT_POST_TYPES,
				__( 'Allowed Post Types', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_archiver_post_types_field' ),
				self::PAGE_SLUG,
				self::GROUP_AUTO_ARCHIVER,
				array( 'class' => Settings::add_own_links() ? 'iawmlf_toggle_setting__auto_archiver' : 'iawmlf_toggle_setting__auto_archiver hidden' )
			);

			add_settings_field(
				Settings::AUTO_ARCHIVER_EXCLUDED_POSTS,
				__( 'Post Exclusions', 'internet-archive-wayback-machine-link-fixer' ),
				array( $this, 'render_auto_archiver_post_exclusions_field' ),
				self::PAGE_SLUG,
				self::GROUP_AUTO_ARCHIVER,
				array( 'class' => Settings::add_own_links() ? 'iawmlf_toggle_setting__auto_archiver' : 'iawmlf_toggle_setting__auto_archiver hidden' )
			);
		}
	}

	/**
	 * Renders the invalid API Keys message.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	public function render_invalid_api_keys_message(): string {
		// If both keys are empty, do not show any message.
		if ( '' === Settings::get_archive_access_key() && '' === Settings::get_archive_secret_key() ) {
			return '';
		}

		if ( ! Settings::has_valid_archive_api_credentials() ) {
			return '<div id="invalid_api_creds"><p class="description">' . esc_html__( 'The Archive.org API keys are invalid. Please check your settings.', 'internet-archive-wayback-machine-link-fixer' ) . '</p></div>' .
			'<div id="unchecked_api_creds" style="display: none;"><p class="description">' . esc_html__( 'API credentials will be verified when you save the settings.', 'internet-archive-wayback-machine-link-fixer' ) . '</p></div>';
		}

		return '';
	}

	/**
	 * Validate the Archive.org keys.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function validate_archive_org_keys(): void {
		// If we are on this page.
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, we are only reading, not using
			return;
		}

		// If the settings were updated.
		if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, we are only reading, not using
			$key        = Settings::get_archive_secret_key();
			$access_key = Settings::get_archive_access_key();

			$system_client = iawmlf_get_system_client();
			$is_valid      = $system_client->is_valid_user( $access_key, $key );

			Settings::update_archive_api_credentials_validity( $is_valid );
		}
	}

	/**
	 * Render the process links field.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function render_process_links_field(): void {
		?>
		<label for="<?php echo esc_attr( Settings::PROCESS_LINKS ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( Settings::PROCESS_LINKS ); ?>"
				name="<?php echo esc_attr( Settings::PROCESS_LINKS ); ?>"
				value="1"
				<?php checked( Settings::is_link_processing_enabled() ); ?>
			/>
			<?php esc_html_e( 'Enable the Link Fixer to scan links in your selected post types. It will find or create archived versions on the Internet Archive to ensure links remain accessible if they break.', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</label>
		<?php
	}

	/**
	 * Renders Link Fixer section - either description or read-only template.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function render_link_fixer_section(): void {
		// Sub-sites always get read-only view — settings are managed at network level.
		if ( Multisite::is_subsite() ) {
			// Process post types logic.
			$enabled_post_types  = array();
			$disabled_post_types = array();
			$allowed_post_types  = Settings::get_allowed_post_types();

			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
				if ( 'attachment' === $post_type->name ) {
					continue;
				}

				if ( in_array( $post_type->name, $allowed_post_types, true ) ) {
					$enabled_post_types[] = $post_type->label;
				} else {
					$disabled_post_types[] = $post_type->label;
				}
			}

			// Render read-only template.
			iawmlf_render_template(
				'admin/settings/link-fixer-readonly.php',
				array(
					'iawmlf_enabled'             => Settings::is_link_processing_enabled(),
					'iawmlf_post_types_enabled'  => $enabled_post_types,
					'iawmlf_post_types_disabled' => $disabled_post_types,
					'iawmlf_scan_existing'       => Settings::should_scan_existing_posts(),
					'iawmlf_fixer_option'        => Settings::get_fixer_option(),
					'iawmlf_exclusions'          => Settings::get_link_exclusions(),
					'iawmlf_check_duration'      => Settings::get_link_check_duration(),
					'iawmlf_failure_threshold'   => Settings::get_failed_count(),
				)
			);
		} else {
			// Normal editable mode description.
			echo '<p class="description">' . esc_html__( 'Enable the Link Fixer to scan links in your selected post types. It will find or create archived versions on the Internet Archive to ensure links remain accessible if they break.', 'internet-archive-wayback-machine-link-fixer' ) . '</p>';
		}
	}

	/**
	 * Renders Auto Archiver section - either description or read-only template.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function render_auto_archiver_section(): void {
		// Sub-sites always get read-only view — settings are managed at network level.
		if ( Multisite::is_subsite() ) {
			// Process post types logic.
			$enabled_post_types  = array();
			$disabled_post_types = array();
			$allowed_post_types  = Settings::own_link_allowed_post_types();

			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
				if ( 'attachment' === $post_type->name ) {
					continue;
				}

				if ( in_array( $post_type->name, $allowed_post_types, true ) ) {
					$enabled_post_types[] = $post_type->label;
				} else {
					$disabled_post_types[] = $post_type->label;
				}
			}

			// Format update interval.
			$update_interval = match ( (int) Settings::own_link_routine_update_interval() ) {
				1 => __( 'Daily', 'internet-archive-wayback-machine-link-fixer' ),
				7 => __( 'Weekly', 'internet-archive-wayback-machine-link-fixer' ),
				30 => __( 'Monthly', 'internet-archive-wayback-machine-link-fixer' ),
				default => sprintf(
					/* translators: %d: Number of days */
					__( 'Every %d days', 'internet-archive-wayback-machine-link-fixer' ),
					(int) Settings::own_link_routine_update_interval()
				),
			};

			// Render read-only template.
			iawmlf_render_template(
				'admin/settings/auto-archiver-readonly.php',
				array(
					'iawmlf_enabled'             => Settings::add_own_links(),
					'iawmlf_post_types_enabled'  => $enabled_post_types,
					'iawmlf_post_types_disabled' => $disabled_post_types,
					'iawmlf_routinely_update'    => Settings::own_link_routinely_update(),
					'iawmlf_update_interval'     => $update_interval,
					'iawmlf_is_production'       => Environmental::is_production(),
				)
			);
		} elseif ( Environmental::is_production() ) {
				echo '<p class="description">' . esc_html__( 'Keep your content securely archived with the Auto Archiver. Each time you update a post, a fresh copy is saved to the Wayback Machine. Ensure your work remains accessible and preserved over time.', 'internet-archive-wayback-machine-link-fixer' ) . '</p>';
		} else {
			echo '<p class="description staging">' . esc_html__( 'Non-production environment detected - auto archiving is disabled to prevent staging and development sites from being archived.', 'internet-archive-wayback-machine-link-fixer' ) . '</p>';
		}
	}

	/**
	 * Render the post types field to select which post types to check.
	 *
	 * @since   1.0.0
	 *
	 * @return  void
	 */
	public function render_fixer_post_types_field(): void {
		echo '<div class="iawmlf_settings_post_types">';
		foreach ( $this->get_post_types( array( 'public' => true ) ) as $post_type ) {
			if ( 'attachment' === $post_type->name ) {
				continue;
			}
			?>
			<label for="<?php echo esc_attr( Settings::ALLOWED_POST_TYPES ); ?>_<?php echo esc_attr( $post_type->name ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( Settings::ALLOWED_POST_TYPES ); ?>_<?php echo esc_attr( $post_type->name ); ?>"
					name="<?php echo esc_attr( Settings::ALLOWED_POST_TYPES ); ?>[]"
					value="<?php echo esc_attr( $post_type->name ); ?>"
					data-group="link_fixer"
					<?php checked( in_array( $post_type->name, Settings::get_allowed_post_types(), true ) ); ?>
				/>
				<?php echo esc_html( $post_type->label ); ?>
			</label>
			<?php
		}
		echo '</div><p class="description">' . esc_html__( 'Please choose which post types will have their content checked and links added to the Wayback Machine.', 'internet-archive-wayback-machine-link-fixer' ) . '</p>';
	}

	/**
	 * Get all post types.
	 *
	 * @param array<string, mixed> $args The arguments to pass to get_post_types.
	 *
	 * @return array
	 */
	public function get_post_types( $args = array( 'public' => true ) ): array {
		// If a multisite and on network admin, get the post types for all sites.
		if ( Multisite::is_network() ) {
			$post_types = array();
			// Switch to each site and get the post types.
			foreach ( get_sites( array( 'number' => 999999 ) ) as $site ) {
				switch_to_blog( absint( $site->blog_id ) );
				$post_types = array_merge( $post_types, get_post_types( $args, 'objects' ) );
				restore_current_blog();
			}

			// Remove duplicate post types.
			$post_types = array_unique( $post_types, SORT_REGULAR );

			return $post_types;
		}

		// If not multisite, or not on network admin, get the post types for the current site.
		return get_post_types( $args, 'objects' );
	}

	/**
	 * Renders the post type field to select which post types can be auto archived.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function render_archiver_post_types_field(): void {
		echo '<div class="iawmlf_settings_post_types">';
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			if ( 'attachment' === $post_type->name ) {
				continue;
			}
			?>
			<label for="<?php echo esc_attr( Settings::ALLOWED_OWN_CONTENT_POST_TYPES ); ?>_<?php echo esc_attr( $post_type->name ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( Settings::ALLOWED_OWN_CONTENT_POST_TYPES ); ?>_<?php echo esc_attr( $post_type->name ); ?>"
					name="<?php echo esc_attr( Settings::ALLOWED_OWN_CONTENT_POST_TYPES ); ?>[]"
					value="<?php echo esc_attr( $post_type->name ); ?>"
					data-group="auto_archiver"
					<?php checked( in_array( $post_type->name, Settings::own_link_allowed_post_types(), true ) ); ?>
				/>
				<?php echo esc_html( $post_type->label ); ?>
			</label>
			<?php
		}
		echo '</div><p class="description">' . esc_html__( 'Please choose which post types will be automatically archived to the Wayback Machine when they are published.', 'internet-archive-wayback-machine-link-fixer' ) . '</p>';
	}

	/**
	 * Render the drop tables on uninstall field.
	 *
	 * @since   1.0.0
	 *
	 * @return  void
	 */
	public function render_drop_tables_on_uninstall_field(): void {
		?>
		<label for="<?php echo esc_attr( Settings::DROP_TABLES_ON_UNINSTALL_KEY ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( Settings::DROP_TABLES_ON_UNINSTALL_KEY ); ?>"
				name="<?php echo esc_attr( Settings::DROP_TABLES_ON_UNINSTALL_KEY ); ?>"
				value="1"
				<?php checked( Settings::drop_tables_on_uninstall() ); ?>
			/><?php esc_html_e( 'If checked, this will remove all local data when the plugin is uninstalled. Leave unchecked if you plan to reinstall this plugin.', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the multisite mode field.
	 *
	 * @since   1.4.0
	 *
	 * @return  void
	 */
	public function render_multisite_mode_field(): void {
		$current_mode          = Settings::get_multisite_links_table_mode();
		$is_network_admin      = Multisite::is_network();
		$clone_state           = Table_Clone_State::load();

		// $clone_state = Table_Clone_State::from_global( array( 1, 2, 3 ), true, true, true );
		// $clone_state->set_sites_completed([1,2]);
		// $clone_state->set_status( Table_Clone_State::STATUS_ERROR );
		// $clone_state->set_status( Table_Clone_State::STATUS_IDLE );
		// $clone_state->set_status( Table_Clone_State::STATUS_IDLE );

		$show_cloning_progress = $clone_state && in_array( $clone_state->get_status(), array( Table_Clone_State::STATUS_ERROR, Table_Clone_State::STATUS_RUNNING, Table_Clone_State::STATUS_COMPLETED ), true );

		if ( $is_network_admin ) {
			// Editable dropdown for network admin.
			?>
			<select
				id="<?php echo esc_attr( Settings::MULTISITE_LINKS_TABLE_MODE ); ?>"
				name="<?php echo esc_attr( Settings::MULTISITE_LINKS_TABLE_MODE ); ?>"
				data-original-value="<?php echo esc_attr( $current_mode ); ?>"
			>
				<option value="<?php echo esc_attr( Multisite::SHARED_LINKS_TABLE_MODE ); ?>" <?php selected( $current_mode, Multisite::SHARED_LINKS_TABLE_MODE ); ?>>
					<?php esc_html_e( 'Shared Links Table - All sites use a single shared links table', 'internet-archive-wayback-machine-link-fixer' ); ?>
				</option>
				<option value="<?php echo esc_attr( Multisite::SEPARATE_LINKS_TABLE_MODE ); ?>" <?php selected( $current_mode, Multisite::SEPARATE_LINKS_TABLE_MODE ); ?>>
					<?php esc_html_e( 'Separate Links Tables - Each site has its own links table', 'internet-archive-wayback-machine-link-fixer' ); ?>
				</option>
			</select>
			<p class="description">
				<?php esc_html_e( 'Choose how links are stored across your multisite network.', 'internet-archive-wayback-machine-link-fixer' ); ?>
			</p>
			<?php iawmlf_render_template( 'admin/settings/clone-config.php' ); ?>

			<?php
		} else {
			// Read-only display for subsite admin.
			$mode_label = Multisite::SHARED_LINKS_TABLE_MODE === $current_mode
				? __( 'Shared Links Table - All sites use a single shared links table', 'internet-archive-wayback-machine-link-fixer' )
				: __( 'Separate Links Tables - Each site has its own links table', 'internet-archive-wayback-machine-link-fixer' );
			?>
			<div class="iawmlf-readonly-setting">
				<span class="iawmlf-readonly-badge">
					<?php esc_html_e( 'Network Setting', 'internet-archive-wayback-machine-link-fixer' ); ?>
				</span>
				<span class="iawmlf-readonly-value">
					<?php echo esc_html( $mode_label ); ?>
				</span>
				<p class="description">
					<?php esc_html_e( 'This setting is managed at the network level. Contact your network administrator to change this value.', 'internet-archive-wayback-machine-link-fixer' ); ?>
				</p>
			</div>
			<?php
		}
		?>
		<div id="iawmlf_multisite_progress" class="iawmlf-progress-message-<?php echo esc_attr( $clone_state ? $clone_state->get_status() : 'idle' ); ?>" style="<?php echo $show_cloning_progress ? 'display: block;' : 'display: none;'; ?>">
			<?php
			iawmlf_render_template(
				'admin/settings/clone-status.php',
				array(
					'iawmlf_clone_state' => $clone_state,
					'iawmlf_all_sites'   => $this->get_multisite_sites_for_js(),
				)
			);
			?>
		</div>
		<?php
	}

	/**
	 * Render the multisite available sites field.
	 *
	 * @since   1.4.0
	 *
	 * @return  void
	 */
	public function render_multisite_available_sites_field(): void {
		if ( ! Multisite::is_network() ) {
			return;
		}

		$selected_sites = Settings::get_multisite_available_sites();
		$all_sites      = get_sites( array( 'number' => 999999 ) );

		echo '<div class="iawmlf_settings_sites_checkboxes">';

		foreach ( $all_sites as $site ) {
			$site_id    = (int) $site->blog_id;
			$is_checked = null === $selected_sites || in_array( $site_id, $selected_sites, true );

			?>
			<label for="<?php echo esc_attr( Settings::MULTISITE_AVAILABLE_SITES . '_' . $site_id ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( Settings::MULTISITE_AVAILABLE_SITES . '_' . $site_id ); ?>"
					name="<?php echo esc_attr( Settings::MULTISITE_AVAILABLE_SITES ); ?>[]"
					value="<?php echo esc_attr( (string) $site_id ); ?>"
					<?php checked( $is_checked ); ?>
				/>
				<?php echo esc_html( get_blog_details( $site_id )->blogname ); ?>
				<span class="site-url">(<?php echo esc_html( get_site_url( $site_id ) ); ?>)</span>
			</label>
			<?php
		}

		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Select which sites can access this plugin. If no sites are selected, the plugin will be disabled on all sites.', 'internet-archive-wayback-machine-link-fixer' ) . '</p>';
	}

	/**
	 * Renders the field for checking existing posts.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function render_check_existing_posts(): void {
		?>
		<label for="<?php echo esc_attr( Settings::SCAN_EXISTING_POSTS ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( Settings::SCAN_EXISTING_POSTS ); ?>"
				name="<?php echo esc_attr( Settings::SCAN_EXISTING_POSTS ); ?>"
				value="1"
				data-group="link_fixer"
				<?php checked( Settings::should_scan_existing_posts() ); ?>
			/>
			<?php esc_html_e( 'When enabled, all posts of the allowed types will be scanned, and their links will be archived in the Wayback Machine.', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'This runs in the background and may take hours or days, depending on post and link count.', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the link exclusions field.
	 *
	 * @since   1.0.0
	 *
	 * @return  void
	 */
	public function render_link_exclusions_field(): void {
		$urls = Settings::get_link_exclusions();

		echo wp_kses(
			sprintf(
				'<div><p>%s</p></div>',
				__( "Enter a list of URLs (or parts of URLs) to exclude from link processing. You can use an asterisk (<code>*</code>) as a wildcard. For example, <code>https://example.com/some-path/*</code> would exclude anything after '/some-path/', and <code>*twitter.com*</code> would exclude any URL containing 'twitter.com'.", 'internet-archive-wayback-machine-link-fixer' )
			),
			array(
				'code' => array(),
				'p'    => array(),
				'div'  => array(),
			)
		);

		?>
		<div id="iawmlf_excluded_links">
			<div class="new-link">
				<input
					type="text"
					id="iawmlf_excluded_links_new"
					placeholder="<?php esc_html_e( 'Add a new exclusion (https://x.com*)', 'internet-archive-wayback-machine-link-fixer' ); ?>"
					data-group="link_fixer"
				/>
				<button id="iawmlf_excluded_links_new_action" data-group="link_fixer" type="button" class="button button-secondary add-exclusion"><?php esc_html_e( 'Add', 'internet-archive-wayback-machine-link-fixer' ); ?></button>
			</div>

			<div id="iawmlf_excluded_empty" style="display: <?php echo empty( $urls ) ? 'block' : 'none'; ?>;">
				<p>
					<?php esc_html_e( 'No exclusions found.', 'internet-archive-wayback-machine-link-fixer' ); ?>
				</p>
			</div>
				<?php
				foreach ( $urls as $index => $url ) {
					echo wp_kses(
						$this->render_excluded_url( $url, (string) $index ),
						array(
							'input'  => array(
								'type'       => array(),
								'id'         => array(),
								'name'       => array(),
								'value'      => array(),
								'data-link'  => array(),
								'data-index' => array(),
								'data-group' => array(),
							),
							'button' => array(
								'type'       => array(),
								'class'      => array(),
								'data-group' => array(),
							),
							'div'    => array(
								'class' => array(),
							),
						)
					);
				}
				?>
		</div>

		<?php
	}

	/**
	 * Render the post exclusions field.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function render_post_exclusions_field(): void {
		$post_ids = Settings::get_link_fixer_excluded_posts();
		?>
		<p>
			<?php esc_html_e( 'Search for posts to exclude from link processing. Excluded posts will not have their links scanned or archived.', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</p>
		<div id="iawmlf_excluded_posts" class="iawmlf-exclusion-list">
			<div class="iawmlf-exclusion-list__search">
				<div class="iawmlf-post-search">
					<input
						type="text"
						class="iawmlf-post-search__input"
						placeholder="<?php esc_attr_e( 'Search by post title, slug, or ID...', 'internet-archive-wayback-machine-link-fixer' ); ?>"
						autocomplete="off"
						data-group="link_fixer"
					/>
					<div class="iawmlf-post-search__dropdown" style="display:none;"></div>
				</div>
			</div>

			<div class="iawmlf-exclusion-list__empty" style="display: <?php echo empty( $post_ids ) ? 'block' : 'none'; ?>;">
				<p>
					<?php esc_html_e( 'No post exclusions found.', 'internet-archive-wayback-machine-link-fixer' ); ?>
				</p>
			</div>

			<?php
			foreach ( $post_ids as $index => $post_id ) {
				$post            = get_post( $post_id );
				$post_type_obj   = $post ? get_post_type_object( $post->post_type ) : null;
				$post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : __( 'Post', 'internet-archive-wayback-machine-link-fixer' );
				echo wp_kses(
					$this->render_excluded_post(
						$post_id,
						$post ? $post->post_title : __( 'Unknown Post', 'internet-archive-wayback-machine-link-fixer' ),
						$post_type_label,
						(string) $index
					),
					array(
						'div'    => array(
							'class'        => array(),
							'data-post-id' => array(),
							'data-index'   => array(),
						),
						'input'  => array(
							'type'  => array(),
							'name'  => array(),
							'value' => array(),
						),
						'span'   => array(
							'class' => array(),
						),
						'small'  => array(),
						'button' => array(
							'type'       => array(),
							'class'      => array(),
							'data-group' => array(),
						),
					)
				);
			}
			?>
		</div>
		<?php
	}

	/**
	 * Renders a row for an excluded post.
	 *
	 * @since 1.4.0
	 *
	 * @param integer $post_id    The post ID.
	 * @param string  $post_title The post title.
	 * @param string  $post_type  The post type label.
	 * @param string  $index      The index of the row.
	 *
	 * @return string
	 */
	private function render_excluded_post( int $post_id, string $post_title, string $post_type, string $index ): string {
		return sprintf(
			'<div class="iawmlf-exclusion-list__item" data-post-id="%d" data-index="%s">
				<input type="hidden" name="%s[]" value="%d" />
				<span class="iawmlf-exclusion-list__item-title"><small>(%d|%s)</small> %s</span>
				<button type="button" class="button button-secondary iawmlf-exclusion-list__remove" data-group="link_fixer">%s</button>
			</div>',
			absint( $post_id ),
			esc_attr( $index ),
			esc_attr( Settings::LINK_FIXER_EXCLUDED_POSTS ),
			absint( $post_id ),
			absint( $post_id ),
			esc_html( $post_type ),
			esc_html( $post_title ),
			esc_html__( 'Remove', 'internet-archive-wayback-machine-link-fixer' )
		);
	}

	/**
	 * Renders the template for a new excluded post row.
	 * Uses placeholders that JS will replace when adding items.
	 *
	 * @since 1.4.0
	 *
	 * @return string
	 */
	private function render_excluded_post_template(): string {
		return sprintf(
			'<div class="iawmlf-exclusion-list__item" data-post-id="{postId}" data-index="{newIndex}">
				<input type="hidden" name="%s[]" value="{postId}" />
				<span class="iawmlf-exclusion-list__item-title"><small>({postId}|{postType})</small> {postTitle}</span>
				<button type="button" class="button button-secondary iawmlf-exclusion-list__remove" data-group="link_fixer">%s</button>
			</div>',
			esc_attr( Settings::LINK_FIXER_EXCLUDED_POSTS ),
			esc_html__( 'Remove', 'internet-archive-wayback-machine-link-fixer' )
		);
	}

	/**
	 * Render the auto archiver post exclusions field.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function render_auto_archiver_post_exclusions_field(): void {
		$post_ids = Settings::get_auto_archiver_excluded_posts();
		?>
		<p>
			<?php esc_html_e( 'Search for posts to exclude from auto archiving. Excluded posts will not be submitted to the Wayback Machine when created or updated.', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</p>
		<div id="iawmlf_excluded_archiver_posts" class="iawmlf-exclusion-list">
			<div class="iawmlf-exclusion-list__search">
				<div class="iawmlf-post-search">
					<input
						type="text"
						class="iawmlf-post-search__input"
						placeholder="<?php esc_attr_e( 'Search by post title, slug, or ID...', 'internet-archive-wayback-machine-link-fixer' ); ?>"
						autocomplete="off"
						data-group="auto_archiver"
					/>
					<div class="iawmlf-post-search__dropdown" style="display:none;"></div>
				</div>
			</div>

			<div class="iawmlf-exclusion-list__empty" style="display: <?php echo empty( $post_ids ) ? 'block' : 'none'; ?>;">
				<p>
					<?php esc_html_e( 'No post exclusions found.', 'internet-archive-wayback-machine-link-fixer' ); ?>
				</p>
			</div>

			<?php
			foreach ( $post_ids as $index => $post_id ) {
				$post            = get_post( $post_id );
				$post_type_obj   = $post ? get_post_type_object( $post->post_type ) : null;
				$post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : __( 'Post', 'internet-archive-wayback-machine-link-fixer' );
				echo wp_kses(
					$this->render_excluded_archiver_post(
						$post_id,
						$post ? $post->post_title : __( 'Unknown Post', 'internet-archive-wayback-machine-link-fixer' ),
						$post_type_label,
						(string) $index
					),
					array(
						'div'    => array(
							'class'        => array(),
							'data-post-id' => array(),
							'data-index'   => array(),
						),
						'input'  => array(
							'type'  => array(),
							'name'  => array(),
							'value' => array(),
						),
						'span'   => array(
							'class' => array(),
						),
						'small'  => array(),
						'button' => array(
							'type'       => array(),
							'class'      => array(),
							'data-group' => array(),
						),
					)
				);
			}
			?>
		</div>
		<?php
	}

	/**
	 * Renders a row for an excluded auto archiver post.
	 *
	 * @since 1.4.0
	 *
	 * @param integer $post_id    The post ID.
	 * @param string  $post_title The post title.
	 * @param string  $post_type  The post type label.
	 * @param string  $index      The index of the row.
	 *
	 * @return string
	 */
	private function render_excluded_archiver_post( int $post_id, string $post_title, string $post_type, string $index ): string {
		return sprintf(
			'<div class="iawmlf-exclusion-list__item" data-post-id="%d" data-index="%s">
				<input type="hidden" name="%s[]" value="%d" />
				<span class="iawmlf-exclusion-list__item-title"><small>(%d|%s)</small> %s</span>
				<button type="button" class="button button-secondary iawmlf-exclusion-list__remove" data-group="auto_archiver">%s</button>
			</div>',
			absint( $post_id ),
			esc_attr( $index ),
			esc_attr( Settings::AUTO_ARCHIVER_EXCLUDED_POSTS ),
			absint( $post_id ),
			absint( $post_id ),
			esc_html( $post_type ),
			esc_html( $post_title ),
			esc_html__( 'Remove', 'internet-archive-wayback-machine-link-fixer' )
		);
	}

	/**
	 * Renders the template for a new excluded auto archiver post row.
	 * Uses placeholders that JS will replace when adding items.
	 *
	 * @since 1.4.0
	 *
	 * @return string
	 */
	private function render_excluded_archiver_post_template(): string {
		return sprintf(
			'<div class="iawmlf-exclusion-list__item" data-post-id="{postId}" data-index="{newIndex}">
				<input type="hidden" name="%s[]" value="{postId}" />
				<span class="iawmlf-exclusion-list__item-title"><small>({postId}|{postType})</small> {postTitle}</span>
				<button type="button" class="button button-secondary iawmlf-exclusion-list__remove" data-group="auto_archiver">%s</button>
			</div>',
			esc_attr( Settings::AUTO_ARCHIVER_EXCLUDED_POSTS ),
			esc_html__( 'Remove', 'internet-archive-wayback-machine-link-fixer' )
		);
	}

	/**
	 * Render the link check duration field.
	 *
	 * @since   1.3.1
	 *
	 * @return  void
	 */
	public function render_link_check_duration_field(): void {
		?>
		<input
			type="number"
			id="<?php echo esc_attr( Settings::LINK_CHECK_DURATION_IN_DAYS ); ?>"
			name="<?php echo esc_attr( Settings::LINK_CHECK_DURATION_IN_DAYS ); ?>"
			value="<?php echo esc_attr( (string) Settings::get_link_check_duration() ); ?>"
			min="1"
			style="width:80px;"
			data-group="link_fixer"
		/>
		<p class="description">
			<?php esc_html_e( 'How often to recheck each link for validity. Avoid checking too often, as temporary outages or maintenance can cause false “broken” results. The default is 7 days.', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the minimum checks before broken field.
	 *
	 * @since   1.3.1
	 *
	 * @return  void
	 */
	public function render_minimum_checks_before_broken_field(): void {
		?>
		<input
			type="number"
			id="<?php echo esc_attr( Settings::MINIMUM_CHECKS_BEFORE_BROKEN ); ?>"
			name="<?php echo esc_attr( Settings::MINIMUM_CHECKS_BEFORE_BROKEN ); ?>"
			value="<?php echo esc_attr( (string) Settings::get_failed_count() ); ?>"
			min="1"
			style="width:80px;"
			data-group="link_fixer"
		/>
		<p class="description">
			<?php esc_html_e( 'Number of consecutive failed checks before a link is marked as broken. Occasional single failures are normal, so use a value high enough to confirm genuine link loss. The default is 5.', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the setting to cast archived links to HTTPS.
	 *
	 * @since 1.3.5
	 *
	 * @return void
	 */
	public function render_cast_archived_to_https_field(): void {
		?>
		<label for="<?php echo esc_attr( Settings::CAST_ARCHIVED_TO_HTTPS ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( Settings::CAST_ARCHIVED_TO_HTTPS ); ?>"
				name="<?php echo esc_attr( Settings::CAST_ARCHIVED_TO_HTTPS ); ?>"
				value="1"
				data-group="link_fixer"
				<?php checked( Settings::should_cast_archived_to_https() ); ?>
			/>
			<?php esc_html_e( 'Force HTTPS', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Enabling this will convert all archived urls from http to https.', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders a row for excluded urls.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url   The URL to render.
	 * @param string $index The index of the URL.
	 *
	 * @return string
	 */
	private function render_excluded_url( string $url, string $index ): string {
		return sprintf(
			'<div class="link">
				<input
					type="text"
					id="%s_%s"
					name="%s[]"
					value="%s"
					data-link="%s"
					data-index="%s"
					data-group="link_fixer"
				/>
				<button data-group="link_fixer" type="button" class="button button-secondary remove-exclusion">%s</button>
			</div>',
			esc_attr( Settings::LINK_EXCLUSIONS ),
			esc_attr( $index ),
			esc_attr( Settings::LINK_EXCLUSIONS ),
			esc_attr( $url ),
			esc_attr( $url ),
			esc_attr( $index ),
			esc_html__( 'Remove', 'internet-archive-wayback-machine-link-fixer' )
		);
	}

	/**
	 * Render the archive api key field.
	 *
	 * @since   1.0.0
	 *
	 * @return  void
	 */
	public function render_archive_api_secret_key(): void {
		?>
		<input
			type="password"
			id="<?php echo esc_attr( Settings::ARCHIVE_ORG_SECRET_KEY ); ?>"
			name="<?php echo esc_attr( Settings::ARCHIVE_ORG_SECRET_KEY ); ?>"
			value="<?php echo esc_attr( Settings::get_archive_secret_key() ); ?>"
			data-previous-value="<?php echo esc_attr( Settings::get_archive_secret_key() ); ?>"
			data-is-valid="<?php echo esc_attr( Settings::has_valid_archive_api_credentials() ? '1' : '0' ); ?>"
			style="width:80%;"
		/>
		<p class="description">
			<?php esc_html_e( 'Archive.org Secret Key', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the archive api secret field.
	 *
	 * @since   1.0.0
	 *
	 * @return  void
	 */
	public function render_archive_api_access_key(): void {
		?>
		<input
			type="password"
			id="<?php echo esc_attr( Settings::ARCHIVE_ORG_ACCESS_KEY ); ?>"
			name="<?php echo esc_attr( Settings::ARCHIVE_ORG_ACCESS_KEY ); ?>"
			value="<?php echo esc_attr( Settings::get_archive_access_key() ); ?>"
			data-previous-value="<?php echo esc_attr( Settings::get_archive_access_key() ); ?>"
			data-is-valid="<?php echo esc_attr( Settings::has_valid_archive_api_credentials() ? '1' : '0' ); ?>"
			style="width:80%;"
		/>
		<p class="description">
			<?php esc_html_e( 'Archive.org Access Key', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the fixer option field.
	 *
	 * @since   1.0.0
	 *
	 * @return  void
	 */
	public function render_fixer_option(): void {
		?>
		<select
			id="<?php echo esc_attr( Settings::FIXER_OPTION ); ?>"
			name="<?php echo esc_attr( Settings::FIXER_OPTION ); ?>"
			data-group="link_fixer"
		>
			<option value="<?php echo esc_attr( Settings::FIXER_OPTION_REPLACE_LINK ); ?>" <?php selected( Settings::get_fixer_option(), Settings::FIXER_OPTION_REPLACE_LINK ); ?>>
				<?php esc_html_e( 'Redirect broken links to snapshots on the Wayback Machine', 'internet-archive-wayback-machine-link-fixer' ); ?>
			</option>
			<option value="<?php echo esc_attr( Settings::FIXER_OPTION_DO_NOTHING ); ?>" <?php selected( Settings::get_fixer_option(), Settings::FIXER_OPTION_DO_NOTHING ); ?>>
				<?php esc_html_e( 'Do not redirect broken links', 'internet-archive-wayback-machine-link-fixer' ); ?>
			</option>
		</select>
		<p class="description">
			<?php esc_html_e( 'Choose how to handle broken links.', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the link icon selection field.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function render_link_icon_field(): void {
		$icons    = Settings::get_available_link_icons();
		$selected = Settings::get_link_icon();
		?>
		<select
			id="<?php echo esc_attr( Settings::LINK_ICON ); ?>"
			name="<?php echo esc_attr( Settings::LINK_ICON ); ?>"
			data-group="link_fixer"
		>
			<?php foreach ( $icons as $icon ) : ?>
				<option value="<?php echo esc_attr( $icon['id'] ); ?>" <?php selected( $selected, $icon['id'] ); ?>>
					<?php echo esc_html( $icon['name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<style id="iawmlf-link-icon-preview-style"><?php echo Settings::get_link_icon_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS rule from Settings, not user input. ?></style>
		<span id="iawmlf_link_icon_preview" class="iawmlf-link-icon-preview">
			<a href="https://web.archive.org/web/noop" onclick="return false;">
				<?php esc_html_e( 'Example Link', 'internet-archive-wayback-machine-link-fixer' ); ?>
			</a>
		</span>
		<p class="description">
			<?php esc_html_e( 'Choose an icon to display next to fixed links.', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the field which is used to set allowing own posts to be added to the wayback machine.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function render_allow_own_posts(): void {
		?>
		<label for="<?php echo esc_attr( Settings::ALLOW_OWN_CONTENT_SUBMISSIONS ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( Settings::ALLOW_OWN_CONTENT_SUBMISSIONS ); ?>"
				name="<?php echo esc_attr( Settings::ALLOW_OWN_CONTENT_SUBMISSIONS ); ?>"
				value="1"
				<?php checked( Settings::add_own_links() ); ?>
			/>
			<?php esc_html_e( 'When active, your own content will be automatically archived on the Wayback Machine each time you publish or update it.', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</label>
		<?php
	}

	/**
	 * Renders the field which is used to allow own posts to be upated routinely.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function render_own_link_routinely_update(): void {
		?>
		<label for="<?php echo esc_attr( Settings::ROUTINELY_UPDATE_WAYBACK_MACHINE ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( Settings::ROUTINELY_UPDATE_WAYBACK_MACHINE ); ?>"
				name="<?php echo esc_attr( Settings::ROUTINELY_UPDATE_WAYBACK_MACHINE ); ?>"
				value="1"
				data-group="auto_archiver"
				<?php checked( Settings::own_link_routinely_update() ); ?>
			/>
			<?php esc_html_e( 'Regularly archive your posts on the Wayback Machine', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</label>
		<?php
	}

	/**
	 * Renders the field which is used to set the interval between updates.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function render_own_link_routinely_update_interval(): void {
		$interval = Settings::own_link_routine_update_interval();
		?>
		<input
			type="number"
			id="<?php echo esc_attr( Settings::ROUTINELY_UPDATE_WAYBACK_MACHINE_INTERVAL ); ?>"
			name="<?php echo esc_attr( Settings::ROUTINELY_UPDATE_WAYBACK_MACHINE_INTERVAL ); ?>"
			value="<?php echo esc_attr( (string) $interval ); ?>"
			min="1"
			step="1"
			data-group="auto_archiver"
		/>
		<p class="description">
			<?php esc_html_e( 'Interval in days for regular archiving.', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</p>
		<?php
	}

	/**
	 * Get multisite sites data for JavaScript.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array{id: int, name: string}>
	 */
	private function get_multisite_sites_for_js(): array {
		if ( ! Multisite::is_network_active() ) {
			return array();
		}

		$sites  = get_sites( array( 'number' => 999999 ) );
		$result = array();

		foreach ( $sites as $site ) {
			$site_id  = (int) $site->blog_id;
			$details  = get_blog_details( $site_id );
			$result[] = array(
				'id'   => $site_id,
				'name' => $details ? $details->blogname : sprintf( 'Site %d', $site_id ),
			);
		}

		return $result;
	}
}

