const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );
const path = require( 'path' );

/**
 * Multisite mode switch: shared -> separate via the clone flow.
 *
 * Drives the network settings page end to end:
 *
 *   1. The mode <select> starts on 'shared'.
 *   2. Choosing 'separate' reveals the clone config panel and disables the
 *      select (the deliberate guard against the normal form save persisting
 *      an un-migrated mode).
 *   3. Only the subsite is selected, the confirmation box is ticked and the
 *      batch runner is started.
 *   4. On completion the UI reports done, and the database confirms the
 *      subsite table now holds the cloned rows and the network mode option
 *      has flipped to 'separate'.
 *   5. After a reload the select shows 'separate' as the persisted value.
 *
 * Requires the wp-env install to be multisite with a /garren/ subsite:
 *
 *   npx wp-env run cli -- wp core multisite-convert --title="MS-E2E"
 *   npx wp-env run cli -- wp site create --slug=garren --title=Garren
 *   npx wp-env run cli -- wp plugin activate internet-archive-wayback-machine-link-fixer --network
 *
 * The spec skips itself when the install is not multisite.
 */

const PLUGIN_DIR = 'wp-content/plugins/internet-archive-wayback-machine-link-fixer';

function wpCli( command ) {
	return execSync(
		`npx wp-env run cli --env-cwd='${ PLUGIN_DIR }' -- ${ command }`,
		{ cwd: path.join( __dirname, '../..' ), encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] }
	)
		.toString()
		.trim();
}

function lastLine( output ) {
	return output.split( '\n' ).pop().trim();
}

function isMultisite() {
	try {
		return 'multisite' === lastLine( wpCli( 'wp eval "echo is_multisite() ? \'multisite\' : \'single\';"' ) );
	} catch ( e ) {
		return false;
	}
}

function runSeeder() {
	const output = execSync(
		`npx wp-env run cli --env-cwd='${ PLUGIN_DIR }' -- wp eval-file e2e/fixtures/seed-multisite-clone.php`,
		{ cwd: path.join( __dirname, '../..' ), encoding: 'utf8' }
	);

	const parse = ( key ) => {
		const m = output.match( new RegExp( `^${ key }=(.+)$`, 'm' ) );
		if ( ! m ) {
			throw new Error( `Seeder did not print ${ key }. Output was:\n${ output }` );
		}
		return m[ 1 ].trim();
	};

	return {
		garrenId:     parseInt( parse( 'GARREN_ID' ), 10 ),
		subsiteTable: parse( 'SUBSITE_TABLE' ),
		sharedTable:  parse( 'SHARED_TABLE' ),
		sharedCount:  parseInt( parse( 'SHARED_COUNT' ), 10 ),
	};
}

function getNetworkMode() {
	return lastLine( wpCli( 'wp network meta get 1 iawmlf_multisite_links_table_mode' ) );
}

function countTableRows( table ) {
	return parseInt(
		lastLine( wpCli( `wp db query "SELECT COUNT(*) FROM ${ table }" --skip-column-names` ) ),
		10
	);
}

test.describe( 'multisite mode clone (shared -> separate)', () => {
	let seeded;

	test.beforeAll( () => {
		test.skip( ! isMultisite(), 'wp-env install is not multisite — see spec header for setup.' );
		seeded = runSeeder();
	} );

	test( 'network admin can switch modes via the clone batch runner', async ( { page } ) => {
		await page.goto( '/wp-admin/network/admin.php?page=iawmlf_settings' );

		const modeSelect = page.locator( '#iawmlf_multisite_links_table_mode' );
		await expect( modeSelect ).toHaveValue( 'shared' );
		await expect( modeSelect ).toBeEnabled();

		// Choosing a different mode reveals the clone config and disables the
		// select so the normal settings save cannot persist an un-migrated mode.
		await modeSelect.selectOption( 'separate' );
		await expect( page.locator( '#iawmlf-clone-config' ) ).toBeVisible();
		await expect( modeSelect ).toBeDisabled();

		// Restrict the clone to the subsite only.
		const garrenCheckbox = page.locator( `#iawmlf-clone-sites input[value="${ seeded.garrenId }"]` );
		await expect( garrenCheckbox ).toBeChecked();
		await page.locator( '#iawmlf-clone-sites input[value="1"]' ).uncheck();

		// The start button stays disabled until the confirmation is ticked.
		const startButton = page.locator( '#iawmlf-clone-start' );
		await expect( startButton ).toBeDisabled();
		await page.locator( '#iawmlf-clone-confirm' ).check();
		await expect( startButton ).toBeEnabled();

		await startButton.click();

		// The batch runner hides the config, shows progress and finishes.
		await expect( page.locator( '#iawmlf-footer-completed' ) ).toBeVisible( { timeout: 60_000 } );

		// Database truth: rows cloned into the subsite table, mode flipped.
		expect( countTableRows( seeded.subsiteTable ) ).toBe( seeded.sharedCount );
		expect( getNetworkMode() ).toBe( 'separate' );

		// After a reload the new mode is the persisted, editable value.
		await page.reload();
		await expect( modeSelect ).toHaveValue( 'separate' );
	} );
} );
