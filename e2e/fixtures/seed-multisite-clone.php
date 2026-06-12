<?php
/**
 * E2E seeder: multisite shared -> separate clone flow.
 *
 * Resets the network to a known starting point for the mode-switch spec:
 *
 *   - links table mode forced to 'shared'
 *   - any persisted clone state removed
 *   - the subsite (garren) links table dropped + its migration log cleared
 *   - the shared links table truncated and seeded with two known links
 *
 * Prints machine-readable facts for the spec:
 *
 *   GARREN_ID=<blog id>
 *   SUBSITE_TABLE=<table name>
 *   SHARED_TABLE=<table name>
 *   SHARED_COUNT=<row count>
 *
 * Run via: wp eval-file e2e/fixtures/seed-multisite-clone.php
 * Requires a multisite install with a site at /garren/.
 */

use Internet_Archive\Wayback_Machine_Link_Fixer\Settings\Settings;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Multisite;
use Internet_Archive\Wayback_Machine_Link_Fixer\Multisite\Table_Clone_State;

if ( ! class_exists( Settings::class ) ) {
	fwrite( STDERR, "Plugin not loaded — is internet-archive-wayback-machine-link-fixer active?\n" );
	exit( 1 );
}

if ( ! is_multisite() ) {
	fwrite( STDERR, "This seeder requires a multisite install (run `wp core multisite-convert` first).\n" );
	exit( 1 );
}

global $wpdb;

// Locate the garren subsite.
$garren_sites = get_sites(
	array(
		'path'   => '/garren/',
		'number' => 1,
	)
);

if ( empty( $garren_sites ) ) {
	fwrite( STDERR, "No /garren/ site found — create it with `wp site create --slug=garren`.\n" );
	exit( 1 );
}

$garren_id     = (int) $garren_sites[0]->blog_id;
$shared_table  = Settings::get_shared_multisite_link_table_name();
$subsite_table = Settings::get_subsite_link_table_name( $garren_id );

// Force a clean starting point: shared mode, no clone state.
Settings::set_multisite_links_table_mode( Multisite::SHARED_LINKS_TABLE_MODE );
Table_Clone_State::delete();

// Mark onboarding AND the wizard as complete (network level on multisite) so
// neither gate hijacks the network settings page with a wizard redirect.
Settings::set_onboarding_status( Settings::ONBOARDING_COMPLETED_OPTION );
Settings::set_wizard_completed( true );

// Drop any subsite table from prior runs and clear its migration log so the
// clone re-creates it from scratch.
$wpdb->query( "DROP TABLE IF EXISTS `$subsite_table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, table names cant be prepared.
switch_to_blog( $garren_id );
delete_option( Settings::MIGRATIONS_KEY );
restore_current_blog();

// Seed the shared table with two known links.
$wpdb->query( "TRUNCATE TABLE `$shared_table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, table names cant be prepared.

$seed_urls = array(
	'https://example.com/iawmlf-e2e-clone-one',
	'https://example.com/iawmlf-e2e-clone-two',
);

foreach ( $seed_urls as $seed_url ) {
	$wpdb->insert(
		$shared_table,
		array(
			'url'    => $seed_url,
			'checks' => '[]',
		),
		array( '%s', '%s' )
	);
}

$shared_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$shared_table`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, table names cant be prepared.

echo "GARREN_ID={$garren_id}\n";
echo "SUBSITE_TABLE={$subsite_table}\n";
echo "SHARED_TABLE={$shared_table}\n";
echo "SHARED_COUNT={$shared_count}\n";
