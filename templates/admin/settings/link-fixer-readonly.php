<?php
/**
 * Read-only Link Fixer settings display for sub-sites in SHARED mode.
 *
 * @since 1.4.0
 *
 * @var bool   $iawmlf_enabled              Whether Link Fixer is enabled.
 * @var array  $iawmlf_post_types_enabled   Enabled post type labels on this site.
 * @var array  $iawmlf_post_types_disabled   Disabled post type labels on this site.
 * @var bool   $iawmlf_scan_existing        Whether to scan existing posts.
 * @var string $iawmlf_fixer_option         Fixer action option.
 * @var array  $iawmlf_exclusions           Link exclusion patterns.
 * @var int    $iawmlf_check_duration       Recheck interval in days.
 * @var int    $iawmlf_failure_threshold    Failed checks before marked broken.
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="iawmlf-readonly-wrapper">
<div class="iawmlf-readonly-setting">
	<span class="iawmlf-readonly-badge">
		<?php esc_html_e( 'Network Setting', 'internet-archive-wayback-machine-link-fixer' ); ?>
	</span>
	<span class="iawmlf-readonly-value iawmlf-inline">
		<strong><?php esc_html_e( 'Link Fixer:', 'internet-archive-wayback-machine-link-fixer' ); ?></strong>
		<?php
		if ( $iawmlf_enabled ) {
			echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> ';
			esc_html_e( 'Enabled', 'internet-archive-wayback-machine-link-fixer' );
		} else {
			echo '<span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span> ';
			esc_html_e( 'Disabled', 'internet-archive-wayback-machine-link-fixer' );
		}
		?>
	</span>
	<?php if ( $iawmlf_enabled ) : ?>
		<span class="iawmlf-readonly-value iawmlf-stacked iawmlf-post-types">
			<strong><?php esc_html_e( 'Post Types:', 'internet-archive-wayback-machine-link-fixer' ); ?></strong>
			<div class="iawmlf-post-types-grid">
				<?php if ( ! empty( $iawmlf_post_types_enabled ) ) : ?>
					<?php foreach ( $iawmlf_post_types_enabled as $iawmlf_type ) : ?>
						<div>
							<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
							<?php echo esc_html( $iawmlf_type ); ?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
				<?php if ( ! empty( $iawmlf_post_types_disabled ) ) : ?>
					<?php foreach ( $iawmlf_post_types_disabled as $iawmlf_type ) : ?>
						<div>
							<span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span>
							<span class="iawmlf-post-type-inactive"><?php echo esc_html( $iawmlf_type ); ?></span>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</span>
		<span class="iawmlf-readonly-value iawmlf-inline">
			<strong><?php esc_html_e( 'Scan Existing Posts:', 'internet-archive-wayback-machine-link-fixer' ); ?></strong>
			<?php
			if ( $iawmlf_scan_existing ) {
				echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> ';
				esc_html_e( 'Yes', 'internet-archive-wayback-machine-link-fixer' );
			} else {
				echo '<span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span> ';
				esc_html_e( 'No', 'internet-archive-wayback-machine-link-fixer' );
			}
			?>
		</span>
		<span class="iawmlf-readonly-value iawmlf-inline">
			<strong><?php esc_html_e( 'Fixer Action:', 'internet-archive-wayback-machine-link-fixer' ); ?></strong>
			<?php
			if ( 'replace_link' === $iawmlf_fixer_option ) {
				esc_html_e( 'Replace broken links with archived versions', 'internet-archive-wayback-machine-link-fixer' );
			} else {
				esc_html_e( 'Do nothing (monitoring only)', 'internet-archive-wayback-machine-link-fixer' );
			}
			?>
		</span>
		<span class="iawmlf-readonly-value <?php echo empty( $iawmlf_exclusions ) ? 'iawmlf-inline' : 'iawmlf-stacked iawmlf-exclusions'; ?>">
			<strong><?php esc_html_e( 'Excluded Links:', 'internet-archive-wayback-machine-link-fixer' ); ?></strong>
			<?php
			if ( empty( $iawmlf_exclusions ) ) {
				esc_html_e( 'None', 'internet-archive-wayback-machine-link-fixer' );
			} else {
				foreach ( $iawmlf_exclusions as $iawmlf_exclusion ) {
					echo '<code>' . esc_html( $iawmlf_exclusion ) . '</code>';
				}
			}
			?>
		</span>
		<span class="iawmlf-readonly-value iawmlf-inline">
			<strong><?php esc_html_e( 'Recheck Interval:', 'internet-archive-wayback-machine-link-fixer' ); ?></strong>
			<?php
			printf(
				/* translators: %d: Number of days */
				esc_html__( 'Every %d days', 'internet-archive-wayback-machine-link-fixer' ),
				(int) $iawmlf_check_duration
			);
			?>
		</span>
		<span class="iawmlf-readonly-value iawmlf-inline">
			<strong><?php esc_html_e( 'Failure Threshold:', 'internet-archive-wayback-machine-link-fixer' ); ?></strong>
			<?php
			printf(
				/* translators: %d: Number of failed checks */
				esc_html__( '%d failed checks before marking as broken', 'internet-archive-wayback-machine-link-fixer' ),
				(int) $iawmlf_failure_threshold
			);
			?>
		</span>
	<?php endif; ?>
	<p class="description">
		<?php esc_html_e( 'These settings are managed at the network level. Contact your network administrator to change these values.', 'internet-archive-wayback-machine-link-fixer' ); ?>
	</p>
</div>
</div>
