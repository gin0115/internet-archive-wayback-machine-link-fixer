<?php
/**
 * Clone configuration panel for multisite table cloning.
 *
 * This template is rendered inline in the settings page when the user
 * selects a different multisite mode and needs to configure the clone.
 *
 * @since 1.4.0
 */

defined( 'ABSPATH' ) || exit;

?>
<div id="iawmlf-clone-config" class="iawmlf-clone-config" style="display: none;">

	<p class="description" style="margin-bottom: 12px;">
		<?php esc_html_e( 'Select which sites to include in the cloning process.', 'internet-archive-wayback-machine-link-fixer' ); ?>
	</p>

	<div id="iawmlf-clone-sites" class="iawmlf_settings_post_types">
		<--no-dev Sites checkboxes will be populated by JavaScript -->
	</div>

	<p class="description" style="margin: 16px 0 8px;">
		<?php esc_html_e( 'Clone options:', 'internet-archive-wayback-machine-link-fixer' ); ?>
	</p>

	<label for="iawmlf-clone-reset-checks">
		<input type="checkbox" id="iawmlf-clone-reset-checks" name="iawmlf_clone_reset_checks" value="1" />
		<?php esc_html_e( 'Reset link check counts and status for all cloned links.', 'internet-archive-wayback-machine-link-fixer' ); ?>
	</label>
	<br />

	<label for="iawmlf-clone-reset-source">
		<input type="checkbox" id="iawmlf-clone-reset-source" name="iawmlf_clone_reset_source" value="1" />
		<?php esc_html_e( 'Clear the source table after cloning completes.', 'internet-archive-wayback-machine-link-fixer' ); ?>
	</label>
	<br />

	<label for="iawmlf-clone-share-settings">
		<input type="checkbox" id="iawmlf-clone-share-settings" name="iawmlf_clone_share_settings" value="1" checked />
		<?php esc_html_e( 'Copy network settings to each site during cloning.', 'internet-archive-wayback-machine-link-fixer' ); ?>
	</label>

	<div class="iawmlf-clone-confirm" style="margin-top: 16px; padding: 12px; background: #fcf3cf; border-left: 4px solid #dba617;">
		<label for="iawmlf-clone-confirm">
			<input type="checkbox" id="iawmlf-clone-confirm" name="iawmlf_clone_confirm" value="1" />
			<strong><?php esc_html_e( 'I understand that this operation will modify link data across selected sites.', 'internet-archive-wayback-machine-link-fixer' ); ?></strong>
		</label>
	</div>

	<p class="submit" style="margin-top: 16px; padding: 0;">
		<button type="button" class="button button-primary" id="iawmlf-clone-start" disabled>
			<?php esc_html_e( 'Start Clone', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</button>
		<button type="button" class="button" id="iawmlf-clone-cancel">
			<?php esc_html_e( 'Cancel', 'internet-archive-wayback-machine-link-fixer' ); ?>
		</button>
		<span class="spinner" id="iawmlf-clone-spinner" style="float: none; margin-left: 4px;"></span>
	</p>

	<div id="iawmlf-clone-message" style="display: none; margin-top: 12px; padding: 12px;"></div>
</div>
