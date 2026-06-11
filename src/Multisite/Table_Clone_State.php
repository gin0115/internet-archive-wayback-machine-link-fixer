<?php

/**
 * Model for managing table clone state in multisite environments.
 *
 * @package Internet_Archive\Wayback_Machine_Link_Fixer\Multisite
 *
 * @since 1.4.0
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Multisite;

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Model for managing table clone state in multisite environments.
 *
 * This class handles the state of table cloning operations across
 * multiple sites in a WordPress multisite network.
 */
class Table_Clone_State {

	/**
	 * Clone operation status constants.
	 */
	public const STATUS_IDLE      = 'idle';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_ERROR     = 'error';

	public const TYPE_FROM_GLOBAL_TO_PER_SITE = 'from_global_to_per_site';
	public const TYPE_FROM_PER_SITE_TO_GLOBAL = 'from_per_site_to_global';

	/**
	 * Type of clone operation.
	 *
	 * @var string
	 */
	private $clone_type;

	/**
	 * Current status of the operation.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * List of site IDs to process.
	 *
	 * @var array<integer>
	 */
	private $sites_to_process;

	/**
	 * List of site IDs that have been completed.
	 *
	 * @var array<integer>
	 */
	private $sites_completed;

	/**
	 * Current blog ID being processed.
	 *
	 * @var integer
	 */
	private $current_blog_id;

	/**
	 * Log entries.
	 *
	 * @var array<string>
	 */
	private $log;

	/**
	 * Whether to reset cloned site checks.
	 *
	 * @var boolean
	 */
	private $reset_checks;

	/**
	 * Whether to reset the source links table.
	 *
	 * @var boolean
	 */
	private $reset_source_table;

	/**
	 * Whether to share settings across sites.
	 *
	 * @var boolean
	 */
	private $share_settings;

	/**
	 * Constructor.
	 *
	 * @param string  $clone_type         Type of clone operation.
	 * @param string  $status             Current status.
	 * @param array   $sites_to_process   List of site IDs to process.
	 * @param array   $sites_completed    List of site IDs completed.
	 * @param integer $current_blog_id    Current blog ID.
	 * @param array   $log                Log entries.
	 * @param boolean $reset_checks       Whether to reset cloned site checks.
	 * @param boolean $reset_source_table Whether to reset the source links table.
	 * @param boolean $share_settings     Whether to share settings across sites.
	 */
	public function __construct(
		string $clone_type = self::TYPE_FROM_GLOBAL_TO_PER_SITE,
		string $status = self::STATUS_IDLE,
		array $sites_to_process = array(),
		array $sites_completed = array(),
		int $current_blog_id = 0,
		array $log = array(),
		bool $reset_checks = false,
		bool $reset_source_table = false,
		bool $share_settings = true
	) {
		$this->clone_type         = $clone_type;
		$this->status             = $status;
		$this->sites_to_process   = $sites_to_process;
		$this->sites_completed    = $sites_completed;
		$this->current_blog_id    = $current_blog_id;
		$this->log                = $log;
		$this->reset_checks       = $reset_checks;
		$this->reset_source_table = $reset_source_table;
		$this->share_settings     = $share_settings;
	}

	/**
	 * Get clone type.
	 *
	 * @return string
	 */
	public function get_clone_type(): string {
		return $this->clone_type;
	}

	/**
	 * Set clone type.
	 *
	 * @param string $clone_type Clone type.
	 *
	 * @return self
	 */
	public function set_clone_type( string $clone_type ): self {
		$this->clone_type = $clone_type;
		return $this;
	}

	/**
	 * Get status.
	 *
	 * @return string
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Set status.
	 *
	 * @param string $status Status.
	 *
	 * @return self
	 */
	public function set_status( string $status ): self {
		$this->status = $status;
		return $this;
	}

	/**
	 * Get sites to process.
	 *
	 * @return array<integer>
	 */
	public function get_sites_to_process(): array {
		return $this->sites_to_process;
	}

	/**
	 * Set sites to process.
	 *
	 * @param array $sites_to_process Sites to process.
	 *
	 * @return self
	 */
	public function set_sites_to_process( array $sites_to_process ): self {
		$this->sites_to_process = $sites_to_process;
		return $this;
	}

	/**
	 * Get sites completed.
	 *
	 * @return array<integer>
	 */
	public function get_sites_completed(): array {
		return $this->sites_completed;
	}

	/**
	 * Set sites completed.
	 *
	 * @param array $sites_completed Sites completed.
	 *
	 * @return self
	 */
	public function set_sites_completed( array $sites_completed ): self {
		$this->sites_completed = $sites_completed;
		return $this;
	}

	/**
	 * Add a site to the completed list.
	 *
	 * @param integer $site_id Site ID.
	 *
	 * @return self
	 */
	public function add_completed_site( int $site_id ): self {
		if ( ! in_array( $site_id, $this->sites_completed, true ) ) {
			$this->sites_completed[] = $site_id;
		}
		return $this;
	}

	/**
	 * Get total number of sites to process.
	 *
	 * @return integer
	 */
	public function get_total_sites(): int {
		return count( $this->sites_to_process );
	}

	/**
	 * Get number of sites completed.
	 *
	 * @return integer
	 */
	public function get_done_sites(): int {
		return count( $this->sites_completed );
	}

	/**
	 * Get current blog ID.
	 *
	 * @return integer
	 */
	public function get_current_blog_id(): int {
		return $this->current_blog_id;
	}

	/**
	 * Set current blog ID.
	 *
	 * @param integer $current_blog_id Current blog ID.
	 *
	 * @return self
	 */
	public function set_current_blog_id( int $current_blog_id ): self {
		$this->current_blog_id = $current_blog_id;
		return $this;
	}

	/**
	 * Add a log entry.
	 *
	 * @param string $message Log message.
	 * @param string $type    Log type.
	 *
	 * @return self
	 */
	public function add_log( string $message, string $type = 'info' ): self {
		$this->log[] = "[{$type}] {$message}";
		return $this;
	}

	/**
	 * Get log entries.
	 *
	 * @return array<string>
	 */
	public function get_log(): array {
		return $this->log;
	}

	/**
	 * Check if operation is running.
	 *
	 * @return boolean
	 */
	public function is_running(): bool {
		return self::STATUS_RUNNING === $this->status;
	}

	/**
	 * Check if operation is completed.
	 *
	 * @return boolean
	 */
	public function is_completed(): bool {
		return self::STATUS_COMPLETED === $this->status;
	}

	/**
	 * Get progress percentage.
	 *
	 * @return float
	 */
	public function get_progress(): float {
		$total_sites = count( $this->sites_to_process );

		if ( 0 === $total_sites ) {
			return 0.0;
		}

		$done_sites = count( $this->sites_completed );
		return round( ( $done_sites / $total_sites ) * 100, 2 );
	}

	/**
	 * Get reset checks setting.
	 *
	 * @return boolean
	 */
	public function get_reset_checks(): bool {
		return $this->reset_checks;
	}

	/**
	 * Set reset checks setting.
	 *
	 * @param boolean $reset_checks Whether to reset cloned site checks.
	 *
	 * @return self
	 */
	public function set_reset_checks( bool $reset_checks ): self {
		$this->reset_checks = $reset_checks;
		return $this;
	}

	/**
	 * Get reset source table setting.
	 *
	 * @return boolean
	 */
	public function get_reset_source_table(): bool {
		return $this->reset_source_table;
	}

	/**
	 * Set reset source table setting.
	 *
	 * @param boolean $reset_source_table Whether to reset the source links table.
	 *
	 * @return self
	 */
	public function set_reset_source_table( bool $reset_source_table ): self {
		$this->reset_source_table = $reset_source_table;
		return $this;
	}

	/**
	 * Get share settings option.
	 *
	 * @return boolean
	 */
	public function get_share_settings(): bool {
		return $this->share_settings;
	}

	/**
	 * Set share settings option.
	 *
	 * @param boolean $share_settings Whether to share settings across sites.
	 *
	 * @return self
	 */
	public function set_share_settings( bool $share_settings ): self {
		$this->share_settings = $share_settings;
		return $this;
	}

	/**
	 * Convert to JSON string.
	 *
	 * @return string
	 */
	public function to_json(): string {
		return wp_json_encode(
			array(
				'clone_type'         => $this->clone_type,
				'status'             => $this->status,
				'sites_to_process'   => $this->sites_to_process,
				'sites_completed'    => $this->sites_completed,
				'current_blog_id'    => $this->current_blog_id,
				'log'                => $this->log,
				'reset_checks'       => $this->reset_checks,
				'reset_source_table' => $this->reset_source_table,
				'share_settings'     => $this->share_settings,
			)
		);
	}

	/**
	 * Create instance from JSON string.
	 *
	 * @param string $json JSON string.
	 *
	 * @return self|null
	 */
	public static function from_json( string $json ): ?self {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		// Sanitize site arrays to ensure they contain only integers.
		$sites_to_process = isset( $data['sites_to_process'] ) && is_array( $data['sites_to_process'] )
			? array_map( 'absint', $data['sites_to_process'] )
			: array();

		$sites_completed = isset( $data['sites_completed'] ) && is_array( $data['sites_completed'] )
			? array_map( 'absint', $data['sites_completed'] )
			: array();

		// Sanitize log array.
		$log = isset( $data['log'] ) && is_array( $data['log'] )
			? array_map( 'sanitize_text_field', $data['log'] )
			: array();

		return new self(
			sanitize_text_field( $data['clone_type'] ?? '' ),
			sanitize_text_field( $data['status'] ?? self::STATUS_IDLE ),
			$sites_to_process,
			$sites_completed,
			absint( $data['current_blog_id'] ?? 0 ),
			$log,
			(bool) ( $data['reset_checks'] ?? false ),
			(bool) ( $data['reset_source_table'] ?? false ),
			(bool) ( $data['share_settings'] ?? true )
		);
	}

	/**
	 * Create a new state for migrating from global to per-site tables.
	 *
	 * @param array   $sites              Array of site IDs to create individual tables for.
	 * @param boolean $reset_checks       Whether to reset cloned site checks.
	 * @param boolean $reset_source_table Whether to reset the source links table.
	 * @param boolean $share_settings     Whether to share settings across sites.
	 *
	 * @return self
	 */
	public static function from_global( array $sites, bool $reset_checks = false, bool $reset_source_table = false, bool $share_settings = true ): self {
		return new self(
			self::TYPE_FROM_GLOBAL_TO_PER_SITE,
			self::STATUS_IDLE,
			$sites,
			array(),
			0,
			array(),
			$reset_checks,
			$reset_source_table,
			$share_settings
		);
	}

	/**
	 * Create a new state for migrating from per-site to global table.
	 *
	 * @param array   $sites              Array of site IDs to merge from individual tables into shared table.
	 * @param boolean $reset_checks       Whether to reset cloned site checks.
	 * @param boolean $reset_source_table Whether to reset the source links table.
	 * @param boolean $share_settings     Whether to share settings across sites.
	 *
	 * @return self
	 */
	public static function to_global( array $sites, bool $reset_checks = false, bool $reset_source_table = false, bool $share_settings = true ): self {
		return new self(
			self::TYPE_FROM_PER_SITE_TO_GLOBAL,
			self::STATUS_IDLE,
			$sites,
			array(),
			0,
			array(),
			$reset_checks,
			$reset_source_table,
			$share_settings
		);
	}

	/**
	 * Save state to network option.
	 *
	 * @return boolean
	 */
	public function save(): bool {
		return update_network_option( 0, Settings::TABLE_CLONE_STATE, $this->to_json() );
	}

	/**
	 * Load state from network option.
	 *
	 * @return self|null
	 */
	public static function load(): ?self {
		$json = get_network_option( 0, Settings::TABLE_CLONE_STATE, '' );

		if ( empty( $json ) ) {
			return null;
		}

		return self::from_json( $json );
	}

	/**
	 * Clear/reset the state (resets all values to defaults).
	 *
	 * @return self
	 */
	public function clear(): self {
		$this->clone_type       = '';
		$this->status           = self::STATUS_IDLE;
		$this->sites_to_process = array();
		$this->sites_completed  = array();
		$this->current_blog_id  = 0;
		$this->log              = array();

		return $this;
	}

	/**
	 * Delete the network option entirely.
	 *
	 * @return boolean
	 */
	public static function delete(): bool {
		return delete_network_option( 0, Settings::TABLE_CLONE_STATE );
	}
}
