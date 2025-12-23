<?php
/**
 * Read-only Auto Archiver settings display for sub-sites in SHARED mode.
 *
 * @since 1.4.0
 *
 * @var bool   $iawmlf_enabled              Whether Auto Archiver is enabled.
 * @var array  $iawmlf_post_types_enabled   Enabled post type labels on this site.
 * @var array  $iawmlf_post_types_disabled   Disabled post type labels on this site.
 * @var bool   $iawmlf_routinely_update     Whether routine archiving is enabled.
 * @var string $iawmlf_update_interval      Routine update interval (formatted string).
 * @var bool   $iawmlf_is_production        Whether site is in production environment.
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="iawmlf-readonly-wrapper">
<?php if ( ! $iawmlf_is_production ) : ?>
	<p class="description staging">
		<?php esc_html_e( 'Non-production environment detected - auto archiving is disabled to prevent staging and development sites from being archived.', 'internet-archive-wayback-machine-link-fixer' ); ?>
	</p>
<?php endif; ?>

<div class="iawmlf-readonly-setting">
	<span class="iawmlf-readonly-badge">
		<?php esc_html_e( 'Network Setting', 'internet-archive-wayback-machine-link-fixer' ); ?>
	</span>
	<span class="iawmlf-readonly-value iawmlf-inline">
		<strong><?php esc_html_e( 'Auto Archiver:', 'internet-archive-wayback-machine-link-fixer' ); ?></strong>
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
			<strong><?php esc_html_e( 'Routinely Archive:', 'internet-archive-wayback-machine-link-fixer' ); ?></strong>
			<?php
			if ( $iawmlf_routinely_update ) {
				echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> ';
				esc_html_e( 'Enabled', 'internet-archive-wayback-machine-link-fixer' );
			} else {
				echo '<span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span> ';
				esc_html_e( 'Disabled', 'internet-archive-wayback-machine-link-fixer' );
			}
			?>
		</span>
		<span class="iawmlf-readonly-value iawmlf-inline">
			<strong><?php esc_html_e( 'Routine Interval:', 'internet-archive-wayback-machine-link-fixer' ); ?></strong>
			<?php echo esc_html( $iawmlf_update_interval ); ?>
		</span>
	<?php endif; ?>
	<p class="description">
		<?php esc_html_e( 'These settings are managed at the network level. Contact your network administrator to change these values.', 'internet-archive-wayback-machine-link-fixer' ); ?>
	</p>
</div>
</div>
