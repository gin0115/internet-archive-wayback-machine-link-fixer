<?php

/**
 * Handles the registration and rendering of the report page.
 *
 * @since 1.2.0
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Dashboard;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Report\Report_Table;
use Internet_Archive\Wayback_Machine_Link_Fixer\Link\Link_Repository;
use Internet_Archive\Wayback_Machine_Link_Fixer\Dashboard\Dashboard_Page;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * The report page.
 */
class Report_Page {

	/**
	 * The page slug.
	 */
	private const SLUG = 'iawmlf-links';

	/**
	 * The link repository.
	 *
	 * @var Link_Repository
	 */
	private $link_repository;

	/**
	 * Holds the current page hook.
	 *
	 * @var string
	 */
	private $hook;

	/**
	 * Creates a new instance of the report page.
	 */
	public function __construct() {
		$this->link_repository = new Link_Repository();
	}

	/**
	 * Generate the page url.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public static function get_page_url(): string {
		// Use network admin URL if we're in network admin context.
		if ( Multisite::is_network() ) {
			return network_admin_url( 'admin.php?page=' . self::SLUG );
		}

		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Register all hooks.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function initialize(): void {

		add_action( 'admin_menu', array( $this, 'register_page' ) );

		if ( Multisite::is_network_active() ) {
			add_action( 'network_admin_menu', array( $this, 'register_network_page' ) );
		}

		add_filter(
			'set-screen-option',
			function ( $status, $option, $value ) {
				return ( 'links_per_page' === $option ) ? (int) $value : $status;
			},
			10,
			3
		);
	}

	/**
	 * Register the report page.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register_page(): void {
		$hook = add_submenu_page(
			Dashboard_Page::DASHBOARD_SLUG,
			__( 'Wayback Link Fixer - Links', 'internet-archive-wayback-machine-link-fixer' ),
			__( 'Links', 'internet-archive-wayback-machine-link-fixer' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render_page' )
		);

		$this->hook = $hook;

		// Add the screen options.
		add_action( "load-$hook", array( $this, 'register_screen_options' ) );

		// Toggle bulk actions.
		add_filter( 'bulk_actions-' . $hook, array( $this, 'add_bulk_actions' ) );

		// Enqueue the scripts and styles.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Registers the network admin report page.
	 *
	 * @return void
	 */
	public function register_network_page(): void {
		$hook = add_submenu_page(
			Dashboard_Page::DASHBOARD_SLUG,
			__( 'Wayback Link Fixer - Links', 'internet-archive-wayback-machine-link-fixer' ),
			__( 'Links', 'internet-archive-wayback-machine-link-fixer' ),
			'manage_network_options',
			self::SLUG,
			array( $this, 'render_page' )
		);

		$this->hook = $hook;

		// Add the screen options.
		add_action( "load-$hook", array( $this, 'register_screen_options' ) );

		// Toggle bulk actions.
		add_filter( 'bulk_actions-' . $hook, array( $this, 'add_bulk_actions' ) );

		// Enqueue the scripts and styles.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Renders the network admin report page.
	 *
	 * @return void
	 */
	public function render_network_page(): void {
		// Empty for now
	}

	/**
	 * Enqueue the scripts and styles for the page.
	 *
	 * @since 1.3.0
	 *
	 * @param string $hook The current page hook.
	 *
	 * @return void
	 */
	public function enqueue_scripts( string $hook ): void {
		// If not this page, bail.
		if ( $hook !== $this->hook ) {
			return;
		}

		// Enqueue the admin styles.
		wp_enqueue_style(
			self::SLUG,
			IAWMLF_URL . 'assets/css/build/style-style.scss.css',
			array(),
			IAWMLF_VERSION
		);

		// Enqueue the admin scripts.
		wp_enqueue_script(
			self::SLUG,
			IAWMLF_URL . 'assets/js/build/link-table.js',
			array(),
			IAWMLF_VERSION,
			true
		);
	}

	/**
	 * Add the bulk actions.
	 *
	 * @since 1.2.0
	 *
	 * @param array $actions The current actions.
	 *
	 * @return array
	 */
	public function add_bulk_actions( array $actions ): array {
		$is_online = Settings::is_archive_api_online();
		// If the API is not online, make all options disabled.
		if ( ! $is_online ) {
			$actions = array(
				'no-op' => __( 'API Offline, options disabled', 'internet-archive-wayback-machine-link-fixer' ),
			);
			return $actions;
		}

		return $actions;
	}

	/**
	 * Render the report page.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function render_page(): void {
		// If we have the link id in the query string, render the single report page.
		if ( isset( $_GET['iawmlf_link_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, Can be linked, so no nonce possible.
			$this->render_single_page();
			return;
		}

		// Otherwise, render the list page.
		$this->render_list_page();
	}

	/**
	 * Register the screen options for the list table.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register_screen_options(): void {
		// If we viewing a single link, do not show the screen options.
		if ( isset( $_GET['iawmlf_link_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, Can be linked, so no nonce possible.
			return;
		}

		// Links per page option.
		$option = 'per_page';
		$args   = array(
			'label'   => __( 'Links', 'internet-archive-wayback-machine-link-fixer' ),
			'default' => 20,
			'option'  => 'links_per_page',
		);

		add_screen_option( $option, $args );

		// Add the screen help tab.
		$screen = get_current_screen();
		$screen->add_help_tab(
			array(
				'id'       => 'iawmlf_help_bulk_actions',
				'title'    => __( 'Bulk Actions', 'internet-archive-wayback-machine-link-fixer' ),
				'callback' => array( $this, 'render_help_bulk_actions' ),
			)
		);
		$screen->add_help_tab(
			array(
				'id'       => 'iawmlf_help_table_columns',
				'title'    => __( 'Table Columns', 'internet-archive-wayback-machine-link-fixer' ),
				'callback' => array( $this, 'render_help_columns' ),
			)
		);
	}

	/**
	 * Render the help tab for Bulk Actions
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_Screen $screen The current screen.
	 * @param array      $args   The arguments passed to the callback.
	 *
	 * @return void
	 */
	public function render_help_bulk_actions( \WP_Screen $screen, array $args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		iawmlf_render_template( 'admin/links-table/help-tab-bulk-actions.php' );
	}

	/**
	 * Render the help tab for Table Columns.
	 *
	 *  @since 1.3.0
	 *
	 * @param \WP_Screen $screen The current screen.
	 * @param array      $args   The arguments passed to the callback.
	 *
	 * @return void
	 */
	public function render_help_columns( \WP_Screen $screen, array $args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		iawmlf_render_template( 'admin/links-table/help-tab-columns.php' );
	}

	/**
	 * Render the report list page.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function render_list_page(): void {
		// Render the list table.
		$table = new Report_Table( new Link_Repository() );

		// Run the bulk actions.
		$table->process_bulk_action();

		// Render any notices.
		$table->render_notices();

		// Get the current page.
		$current_page = isset( $_REQUEST['page'] ) ? \sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : self::SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, Can be linked, so no nonce possible.

		echo '<div class="wrap">';
		printf(
			'<h1 class="wp-heading-inline">%s</h1>',
			esc_html__( 'Wayback Link Fixer - Links', 'internet-archive-wayback-machine-link-fixer' ),
		);

		echo '<hr class="wp-header-end">';

		// If we have a post id in params, show a message.
		if ( array_key_exists( 'iawmlf_filtered_post_id', $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, Can be linked, so no nonce possible.
			$post_id = absint( wp_unslash( $_GET['iawmlf_filtered_post_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, Can be linked, so no nonce possible.

			// Get the post title.
			$post = get_post( $post_id );

			// Show if we have a valid post.
			if ( $post ) {
				$body = sprintf(
					// translators: %1$s is the post title, %2$s is the link to view all links.
					__( 'Showing links for %1$s <a href="%2$s">(Show all links)</a>', 'internet-archive-wayback-machine-link-fixer' ),
					esc_html( $post->post_title ),
					esc_url( self::get_page_url() ?? '' )
				);

				printf(
					// translators: %s is the body of the message.
					'<div class="notice notice-info"><p>%s</p></div>',
					wp_kses( $body, array( 'a' => array( 'href' => array() ) ) )
				);
			}
		}

		// Render the table.
		$table->prepare_items();
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $current_page ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, Can be linked, so no nonce possible.
		$table->search_box( __( 'Search', 'internet-archive-wayback-machine-link-fixer' ), 'iawmlf-link-search' );
		$table->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Render the single page.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function render_single_page(): void {

		// Get the link ID.
		$link_id = isset( $_GET['iawmlf_link_id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended, Can be linked, so no nonce possible.
			? absint( wp_unslash( $_GET['iawmlf_link_id'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended, Can be linked, so no nonce possible
			: 0;

		// Validate link ID.
		if ( 0 === $link_id ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'The link does not exist.', 'internet-archive-wayback-machine-link-fixer' )
			);
			return;
		}

		// Get the link from repository.
		$link = $this->link_repository->find_by_id( $link_id );

		// Validate link exists.
		if ( ! $link ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'The link is not valid.', 'internet-archive-wayback-machine-link-fixer' )
			);
			return;
		}

		// Build posts data structure based on context.
		$posts_data = array();

		if ( Multisite::is_network() ) {
			// NETWORK ADMIN: Get posts from all sites.
			$sites = get_sites( array( 'number' => 1000 ) ); // Adjust limit if needed.

			foreach ( $sites as $site ) {
				// Get post IDs for this link on this site.
				$post_ids = $this->link_repository->get_post_ids_from_link_id(
					$link->get_id(),
					(int) $site->blog_id
				);

				// Skip if no posts on this site.
				if ( empty( $post_ids ) ) {
					continue;
				}

				// Switch to site context to get post data and URLs.
				switch_to_blog( absint( $site->blog_id ) );

				// Get post objects (filtering out nulls).
				$posts = array_filter( array_map( 'get_post', array_unique( $post_ids ) ) );

				// Get site name.
				$site_name = get_bloginfo( 'name' );

				// Build post data array while in correct blog context.
				$post_data = array();
				foreach ( $posts as $post ) {
					$post_type_object = get_post_type_object( $post->post_type );

					// Build post type link (special case for 'post' type).
					$post_type_link = 'post' === $post->post_type
						? admin_url( 'edit.php' )
						: admin_url( 'edit.php?post_type=' . $post->post_type );

					$post_data[] = array(
						'id'              => $post->ID,
						'title'           => $post->post_title,
						'post_type'       => $post->post_type,
						'post_type_label' => $post_type_object ? $post_type_object->labels->singular_name : $post->post_type,
						'post_type_link'  => $post_type_link,
						'status'          => get_post_status( $post->ID ),
						'edit_link'       => get_edit_post_link( $post->ID ),
						'view_link'       => get_permalink( $post->ID ),
					);
				}

				// Restore original context.
				restore_current_blog();

				// Store in structure: blog_id => ['site_name' => ..., 'posts' => ...].
				if ( ! empty( $post_data ) ) {
					$posts_data[ (int) $site->blog_id ] = array(
						'site_name' => $site_name,
						'posts'     => $post_data,
					);
				}
			}
		} else {
			// SINGLE SITE or SITE ADMIN: Current site only.
			$post_ids = $this->link_repository->get_post_ids_from_link_id( $link->get_id() );
			$posts    = array_filter( array_map( 'get_post', array_unique( $post_ids ) ) );

			// Build post data array (no context switching needed).
			$post_data = array();
			foreach ( $posts as $post ) {
				$post_type_object = get_post_type_object( $post->post_type );

				// Build post type link (special case for 'post' type).
				$post_type_link = 'post' === $post->post_type
					? admin_url( 'edit.php' )
					: admin_url( 'edit.php?post_type=' . $post->post_type );

				$post_data[] = array(
					'id'              => $post->ID,
					'title'           => $post->post_title,
					'post_type'       => $post->post_type,
					'post_type_label' => $post_type_object ? $post_type_object->labels->singular_name : $post->post_type,
					'post_type_link'  => $post_type_link,
					'status'          => get_post_status( $post->ID ),
					'edit_link'       => get_edit_post_link( $post->ID ),
					'view_link'       => get_permalink( $post->ID ),
				);
			}

			// Wrap in same structure for template consistency.
			if ( ! empty( $post_data ) ) {
				$posts_data[ get_current_blog_id() ] = array(
					'site_name' => get_bloginfo( 'name' ),
					'posts'     => $post_data,
				);
			}
		}

		// Render the template.
		iawmlf_render_template(
			'admin/reports/link-details.php',
			array(
				'iawmlf_link'       => $link,
				'iawmlf_posts'      => $posts_data, // Now structured by site.
				'iawmlf_back_url'   => wp_get_referer() ?: self::get_page_url(), // phpcs:ignore Universal.Operators.DisallowShortTernary.Found, returns false, so cant use ??
				'iawmlf_is_network' => Multisite::is_network(),
			)
		);
	}
}
