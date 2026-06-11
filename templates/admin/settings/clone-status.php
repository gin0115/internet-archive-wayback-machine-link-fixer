<?php
/**
 * Clone status display for multisite table cloning progress.
 *
 * Always rendered with all network sites. JS shows/hides and updates elements.
 * When a Table_Clone_State exists, the template reflects that state on page load.
 * When no state exists, the template renders as a hidden skeleton ready for JS.
 *
 * @since 1.4.0
 *
 * @var Table_Clone_State|null $iawmlf_clone_state The clone state object (null if no active migration).
 * @var array                  $iawmlf_all_sites   Array of all network sites [{id, name}].
 */

defined( 'ABSPATH' ) || exit;

use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Table_Clone_State;

// Determine state values — use defaults when no state exists (skeleton mode).
$iawmlf_has_state        = ! empty( $iawmlf_clone_state );
$iawmlf_status           = $iawmlf_has_state ? $iawmlf_clone_state->get_status() : Table_Clone_State::STATUS_IDLE;
$iawmlf_sites_to_process = $iawmlf_has_state ? $iawmlf_clone_state->get_sites_to_process() : array();
$iawmlf_sites_completed  = $iawmlf_has_state ? $iawmlf_clone_state->get_sites_completed() : array();
$iawmlf_logs             = $iawmlf_has_state ? $iawmlf_clone_state->get_log() : array();
$iawmlf_progress         = $iawmlf_has_state ? $iawmlf_clone_state->get_progress() : 0;
$iawmlf_done_sites       = $iawmlf_has_state ? $iawmlf_clone_state->get_done_sites() : 0;
$iawmlf_total_sites      = $iawmlf_has_state ? $iawmlf_clone_state->get_total_sites() : 0;

$iawmlf_clone_type = '';
if ( $iawmlf_has_state ) {
	$iawmlf_clone_type = Table_Clone_State::TYPE_FROM_GLOBAL_TO_PER_SITE === $iawmlf_clone_state->get_clone_type()
		? __( 'Shared to Per-Site', 'internet-archive-wayback-machine-link-fixer' )
		: __( 'Per-Site to Shared', 'internet-archive-wayback-machine-link-fixer' );
}

// Determine status icon and label.
$iawmlf_status_icon  = 'clock';
$iawmlf_status_label = __( 'Idle', 'internet-archive-wayback-machine-link-fixer' );
switch ( $iawmlf_status ) {
	case Table_Clone_State::STATUS_RUNNING:
		$iawmlf_status_icon  = 'update';
		$iawmlf_status_label = __( 'In Progress', 'internet-archive-wayback-machine-link-fixer' );
		break;
	case Table_Clone_State::STATUS_COMPLETED:
		$iawmlf_status_icon  = 'yes-alt';
		$iawmlf_status_label = __( 'Completed', 'internet-archive-wayback-machine-link-fixer' );
		break;
	case Table_Clone_State::STATUS_ERROR:
		$iawmlf_status_icon  = 'warning';
		$iawmlf_status_label = __( 'Error', 'internet-archive-wayback-machine-link-fixer' );
		break;
}

?>
<div class="iawmlf-clone-status" data-status="<?php echo esc_attr( $iawmlf_status ); ?>">

	<!-- Status Header -->
	<div class="iawmlf-status-header">
		<h4 class="iawmlf-status-title">
			<?php esc_html_e( 'Table Clone Status', 'internet-archive-wayback-machine-link-fixer' ); ?>
			<span class="iawmlf-clone-type" id="iawmlf-clone-type"><?php echo esc_html( $iawmlf_clone_type ); ?></span>
		</h4>
		<span class="iawmlf-status-badge iawmlf-status-<?php echo esc_attr( $iawmlf_status ); ?>" id="iawmlf-status-badge">
			<span class="dashicons dashicons-<?php echo esc_attr( $iawmlf_status_icon ); ?>" id="iawmlf-status-icon"></span>
			<span id="iawmlf-status-label"><?php echo esc_html( $iawmlf_status_label ); ?></span>
		</span>
	</div>

	<!-- Progress Bar -->
	<div class="iawmlf-progress-info" id="iawmlf-progress-info" style="<?php echo Table_Clone_State::STATUS_RUNNING !== $iawmlf_status && ! $iawmlf_has_state ? 'display: none;' : ''; ?>">
		<div class="iawmlf-progress-bar">
			<div class="iawmlf-progress-fill" id="iawmlf-progress-fill" style="width: <?php echo esc_attr( $iawmlf_progress ); ?>%"></div>
		</div>
		<div class="iawmlf-progress-text" id="iawmlf-progress-text">
			<?php
			printf(
				/* translators: %1$d: completed sites, %2$d: total sites, %3$s: percentage */
				esc_html__( '%1$d of %2$d sites completed (%3$s%%)', 'internet-archive-wayback-machine-link-fixer' ),
				intval( $iawmlf_done_sites ),
				intval( $iawmlf_total_sites ),
				esc_html( number_format( $iawmlf_progress, 1 ) )
			);
			?>
		</div>
	</div>

	<!-- Sites List — all network sites rendered, hidden by default unless in state -->
	<div class="iawmlf-clone-section">
		<h5 class="iawmlf-section-label"><?php esc_html_e( 'Sites', 'internet-archive-wayback-machine-link-fixer' ); ?></h5>
		<div class="iawmlf-sites-list" id="iawmlf-sites-list">
			<?php foreach ( $iawmlf_all_sites as $iawmlf_site ) : ?>
				<?php
				$iawmlf_site_id   = (int) $iawmlf_site['id'];
				$iawmlf_site_name = $iawmlf_site['name'];

				// Determine badge state.
				$iawmlf_in_process   = in_array( $iawmlf_site_id, $iawmlf_sites_to_process, true );
				$iawmlf_is_completed = in_array( $iawmlf_site_id, $iawmlf_sites_completed, true );

				// Badge CSS class.
				$iawmlf_badge_class = 'iawmlf-site-pending';
				$iawmlf_badge_icon  = 'clock';
				if ( $iawmlf_is_completed ) {
					$iawmlf_badge_class = 'iawmlf-site-completed';
					$iawmlf_badge_icon  = 'yes';
				}

				// Hide badges not in the current process (or all if no state).
				$iawmlf_badge_hidden = ! $iawmlf_in_process;
				?>
				<span
					class="iawmlf-site-badge <?php echo esc_attr( $iawmlf_badge_class ); ?>"
					data-site-id="<?php echo esc_attr( $iawmlf_site_id ); ?>"
					style="<?php echo $iawmlf_badge_hidden ? 'display: none;' : ''; ?>"
				>
					<span class="dashicons dashicons-<?php echo esc_attr( $iawmlf_badge_icon ); ?>"></span>
					<?php echo esc_html( $iawmlf_site_name ); ?>
				</span>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- Options -->
	<?php
	$iawmlf_opt_reset_checks   = $iawmlf_has_state ? $iawmlf_clone_state->get_reset_checks() : false;
	$iawmlf_opt_reset_source   = $iawmlf_has_state ? $iawmlf_clone_state->get_reset_source_table() : false;
	$iawmlf_opt_share_settings = $iawmlf_has_state ? $iawmlf_clone_state->get_share_settings() : false;
	?>
	<p class="iawmlf-clone-options-text" id="iawmlf-clone-options">
		<strong><?php esc_html_e( 'Options:', 'internet-archive-wayback-machine-link-fixer' ); ?></strong>
		<span class="<?php echo $iawmlf_opt_reset_checks ? 'iawmlf-option-enabled' : 'iawmlf-option-disabled'; ?>" data-option="reset_checks">
			<span class="iawmlf-option-icon"><?php echo $iawmlf_opt_reset_checks ? '✓ ' : '✗ '; ?></span>
			<?php esc_html_e( 'Reset Checks', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</span>
		<span class="<?php echo $iawmlf_opt_reset_source ? 'iawmlf-option-enabled' : 'iawmlf-option-disabled'; ?>" data-option="reset_source">
			<span class="iawmlf-option-icon"><?php echo $iawmlf_opt_reset_source ? '✓ ' : '✗ '; ?></span>
			<?php esc_html_e( 'Clear Source', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</span>
		<span class="<?php echo $iawmlf_opt_share_settings ? 'iawmlf-option-enabled' : 'iawmlf-option-disabled'; ?>" data-option="share_settings">
			<span class="iawmlf-option-icon"><?php echo $iawmlf_opt_share_settings ? '✓ ' : '✗ '; ?></span>
			<?php esc_html_e( 'Share Settings', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</span>
	</p>

	<!-- Logs -->
	<div class="iawmlf-clone-section iawmlf-clone-logs">
		<div class="iawmlf-logs-header" id="iawmlf-logs-toggle">
			<h5 class="iawmlf-logs-title">
				<span class="dashicons dashicons-list-view"></span>
				<?php esc_html_e( 'Clone Log', 'internet-archive-wayback-machine-link-fixer' ); ?>
				<span class="iawmlf-log-count" id="iawmlf-log-count">(<?php echo esc_html( count( $iawmlf_logs ) ); ?>)</span>
			</h5>
			<span class="iawmlf-logs-toggle"><?php esc_html_e( 'Show', 'internet-archive-wayback-machine-link-fixer' ); ?></span>
		</div>
		<div class="iawmlf-log-entries" id="iawmlf-log-entries" style="display: none;">
			<?php foreach ( $iawmlf_logs as $iawmlf_log_entry ) : ?>
				<div class="iawmlf-log-entry"><?php echo esc_html( $iawmlf_log_entry ); ?></div>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- Footer -->
	<div class="iawmlf-clone-footer" id="iawmlf-clone-footer">
		<!-- Running state -->
		<div class="iawmlf-footer-running" id="iawmlf-footer-running" style="<?php echo Table_Clone_State::STATUS_RUNNING !== $iawmlf_status ? 'display: none;' : ''; ?>">
			<span class="iawmlf-clone-running-indicator">
				<span class="dashicons dashicons-update-alt iawmlf-spin"></span>
				<?php esc_html_e( 'Clone in progress...', 'internet-archive-wayback-machine-link-fixer' ); ?>
			</span>
			<p class="iawmlf-clone-notice">
				<?php esc_html_e( 'Please do not close this page while cloning is in progress.', 'internet-archive-wayback-machine-link-fixer' ); ?>
			</p>
		</div>

		<!-- Resume state (shown by JS when migration was interrupted) -->
		<div class="iawmlf-footer-resume" id="iawmlf-footer-resume" style="display: none;">
			<button type="button" class="button button-primary" id="iawmlf-resume-clone">
				<span class="dashicons dashicons-controls-play"></span>
				<?php esc_html_e( 'Resume Migration', 'internet-archive-wayback-machine-link-fixer' ); ?>
			</button>
			<p class="iawmlf-clone-notice">
				<?php esc_html_e( 'Migration was interrupted. Click Resume to continue from where it left off.', 'internet-archive-wayback-machine-link-fixer' ); ?>
			</p>
		</div>

		<!-- Completed state -->
		<div class="iawmlf-footer-completed" id="iawmlf-footer-completed" style="<?php echo Table_Clone_State::STATUS_COMPLETED !== $iawmlf_status ? 'display: none;' : ''; ?>">
			<button type="button" class="button button-secondary" id="iawmlf-dismiss-clone">
				<?php esc_html_e( 'Acknowledge &amp; Close', 'internet-archive-wayback-machine-link-fixer' ); ?>
			</button>
		</div>

		<!-- Error state -->
		<div class="iawmlf-footer-error" id="iawmlf-footer-error" style="<?php echo Table_Clone_State::STATUS_ERROR !== $iawmlf_status ? 'display: none;' : ''; ?>">
			<div class="iawmlf-skipped-summary" id="iawmlf-skipped-summary" style="display: none;"></div>
			<button type="button" class="button button-secondary" id="iawmlf-dismiss-clone-error">
				<?php esc_html_e( 'Acknowledge &amp; Close', 'internet-archive-wayback-machine-link-fixer' ); ?>
			</button>
		</div>
	</div>

</div>
