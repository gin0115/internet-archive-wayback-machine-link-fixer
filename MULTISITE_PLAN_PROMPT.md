# Multisite Shared/Separate Links Table — Full Context & Implementation Plan

> **Purpose of this document:** This is a self-contained briefing + task prompt to hand to a model/engineer
> picking up the `multisite_update-migrations-for-multsite-mode` branch of the
> *Internet Archive Wayback Machine Link Fixer* WordPress plugin. It explains the goal, why the design
> is the way it is, what is built, exactly what is missing or broken, and the plan of work.

---

## 1. What we are trying to do

The plugin scans post content for links, stores every discovered URL in a custom table
(`{prefix}iawmlf_link_archive`, schema in `migrations/Migration_1.php`), periodically checks each
link, and when a link is confirmed broken swaps it for a Wayback Machine snapshot. Up to v1.3.x the
plugin was effectively single-site: one links table per install.

This branch (version header `2.0.0-MS`) adds **first-class multisite support** with two storage modes:

| Mode | Table(s) | Default when |
|---|---|---|
| `shared` | One network-wide table: `{base_prefix}iawmlf_link_archive` | Plugin is **network activated** |
| `separate` | One table per subsite: `{blog_prefix}iawmlf_link_archive` | Plugin is activated **on a single site** of a multisite |

The mode lives in the network option `iawmlf_multisite_links_table_mode`
(`Settings::MULTISITE_LINKS_TABLE_MODE`), is set at activation by `Multisite::setup()`
(`functions.php → iawmlf_activate()`), and every table consumer resolves the table through
`Settings::get_link_table_name()` which routes on the mode. A network admin can also restrict which
subsites the plugin runs on via `iawmlf_multisite_available_sites` (checked in
`Multisite::should_enable_site()` and gated in `Integrations::initialize()`).

Crucially, the network admin must be able to **switch modes after data exists**, which means
migrating link data between one shared table and N per-site tables — safely, resumably, and without
timing out.

## 2. Why "creative" approaches are needed for a shared table

WordPress gives us nothing for this; several constraints force unusual designs:

1. **The links table has no `site_id` column.** A URL's broken/archived status is *global truth* —
   the same URL is broken for every site. The table is deliberately site-agnostic, keyed by URL.
   Shared mode is therefore a natural network-wide dedup cache: one check per URL for the entire
   network, massively reducing Wayback Machine API calls and Action Scheduler load.
   The cost: there is **no record of which site "owns" a link**, which shapes everything below.

2. **WP has no concept of per-plugin "network tables".** Table naming must be hand-rolled:
   `$wpdb->base_prefix` for shared, `$wpdb->get_blog_prefix( $site_id )` for per-site
   (`Settings::get_shared_multisite_link_table_name()` / `get_subsite_link_table_name()`).

3. **Activation context is ambiguous.** The same plugin can be network-activated, or activated on
   one subsite of a multisite, or activated on a subsite *after* a network deactivation. The
   migration system had to be reworked so `Migration_1::up()/down()` accept an **explicit table
   name**, and `Migrations::multisite_up()/multisite_down()` can create/drop a *specific site's*
   table. The migration log itself (`iawmlf_migration_log`) is mode-aware: normally read via
   network options, but read/written per-blog (`Settings::migrations( true )`) when managing
   per-site tables. `Migration_2` exists purely to guarantee the shared table exists on multisite
   even when the "assumed" (current-blog) table name differs from the shared name.

4. **Switching modes moves data with no ownership info:**
   - **shared → separate:** we cannot know which links belong to which site, so the *entire* shared
     table is cloned into every selected subsite's new table (`Table_Manager::clone_to_separate_tables()`),
     with options to reset check history (`checks='[]', is_broken=0`) and truncate the source after
     the last site. Each site then organically re-validates / rescans.
   - **separate → shared:** the same URL may exist in several per-site tables with conflicting
     state. `Table_Manager::migrate_to_shared_table()` INSERT..SELECTs the non-duplicates directly,
     then queues the duplicates in 50-row chunks to `Merge_Duplicate_Links_Batch_Event`
     (Action Scheduler, queued on the main site for cron reliability). Merge rules: `is_broken`
     OR'd, `excluded` OR'd, `checks` JSON arrays concatenated; `archived` / `message` /
     `redirect_url` / `archive_process` are **first-won** (untouched). Every merged row is then
     re-queued for validation (`Recheck_Links_Batch_Event` → `Link_Access_Validator_Event`).
     A per-site countdown counter (network option `iawmlf_merge_pending_{site_id}`) ensures the
     subsite table is dropped only when its **last** merge chunk completes.

5. **Long-running work can't run in one request, and WP-cron is unreliable.** The first
   implementation drove shared→separate via a self-re-queueing Action Scheduler chain
   (`Migrate_From_Shared_To_Per_Site_Event`, now commented out in `Event_Controller`). It was
   replaced by a **JS-driven sequential AJAX batch runner** (`assets/js/src/admin_multisite_clone.js`):
   one site per `admin-ajax` request (`Clone_Start_Ajax` → N × `Clone_Process_Site_Ajax` →
   `Clone_Dismiss_Ajax`), with retry-once-then-skip per site, all driven from the open settings page.
   Progress is persisted server-side in `Table_Clone_State` (JSON in network option
   `iawmlf_table_clone_state`) so an interrupted run renders a **Resume** UI on reload. The
   separate→shared *duplicate merge* tail still runs on Action Scheduler because it can outlive the
   page session safely.

6. **Preventing accidental mode flips.** The mode `<select>` sits inside the normal network
   settings form, whose save handler (`Settings_Page::handle_network_settings_save()`) persists any
   posted value. The guard is deliberate and subtle: when the user changes the select, the JS
   **disables it** (disabled inputs are not submitted) and routes the change through the clone
   flow; the server only flips `iawmlf_multisite_links_table_mode` inside
   `Clone_Process_Site_Ajax` once **all** sites in the state have completed. Settings on multisite
   are otherwise always network-level (`Settings::get_multisite_aware_option()`), with subsite
   settings pages rendering read-only "Network Setting" badges.

## 3. What is already built (this branch)

- `src/Multisite/Multisite.php` — mode/context helpers, `setup()`, `should_enable_site()`.
- `src/Settings/Settings.php` — mode get/set, table-name resolvers, network-aware option plumbing,
  `MULTISITE_AVAILABLE_SITES`, `TABLE_CLONE_STATE` keys.
- `src/Migration/*` + `migrations/Migration_1.php` (table-name param) + `migrations/Migration_2.php`
  (ensure shared table) + multisite up/down + mode-aware migration log.
- `src/Multisite/Table_Manager.php` — both clone directions + `reset_site_links_table()` + logging.
- `src/Multisite/Table_Clone_State.php` — resumable state model (type, status, sites, log, options),
  JSON ⇄ network option.
- `src/Ajax/Clone_Start_Ajax.php`, `Clone_Process_Site_Ajax.php`, `Clone_Dismiss_Ajax.php` —
  registered in `Ajax_Controller` (nonce + `manage_network_options` checks).
- `assets/js/src/admin_multisite_clone.js` — batch runner, progress UI, retry/skip, resume, dismiss.
- `templates/admin/settings/clone-config.php` + `clone-status.php` — server-rendered UI skeletons.
- `src/Multisite/Event/Merge_Duplicate_Links_Batch_Event.php`, `Recheck_Links_Batch_Event.php` —
  wired in `Event_Controller`. `Migrate_From_Shared_To_Per_Site_Event.php` is dead/disabled.
- `Settings_Page` — network settings page + custom save handler, mode field rendering both
  clone templates, available-sites checkboxes, subsite read-only variants.
- Wizard/dashboard gating for subsites (network handles onboarding).
- Tests: `tests/bootstrap-multisite.php`, `tests/wp-config-multisite.php`,
  `tests/Multisite/Test_Activation_Mode_Switch_Multisite.php`, `Test_Settings_Multisite.php`;
  multisite tests are in the CI workflow.

## 4. Exactly what is missing / broken

### Functional gaps
1. **`share_settings` is a no-op.** Collected in the UI, stored in `Table_Clone_State`, displayed in
   the status panel — but no code ever copies network settings to per-site options. Worse,
   `Settings::get_multisite_aware_option()` *always* reads network options on multisite regardless
   of mode, so per-site settings don't exist as a concept even in `separate` mode. The semantics
   must be decided (do subsites get independent settings in separate mode?) and implemented, or the
   option removed.
2. **Blog context leak:** `Table_Manager::clone_to_separate_tables()` calls `switch_to_blog()` but
   never `restore_current_blog()` on the success path (only before the SQL-error throw). The dead
   AS event used to restore in its `finally`; the AJAX path (`Clone_Process_Site_Ajax`) does not.
   Subsequent state-saving in that request runs in the wrong blog context.
3. **separate → shared never ensures the shared table exists** before `INSERT..SELECT`
   (`migrate_to_shared_table()`). If the network started per-site-activated, Migration_2 may never
   have created it.
4. **Wrong-blog migration-log bookkeeping:** `Merge_Duplicate_Links_Batch_Event` finally-block calls
   `Migrations::multisite_down( $site_id )`, which reads/writes the migration log via `get_option`
   on the **current** blog (the AS runner site / main site), not the subsite whose table is being
   dropped. Conversely `multisite_up()` is invoked *after* `switch_to_blog()` so it writes to the
   subsite. The per-blog migration-log convention is inconsistent between the two paths.
5. **Non-atomic merge counter:** the `iawmlf_merge_pending_{site_id}` decrement is
   read-modify-write on a network option; concurrent Action Scheduler workers can race → subsite
   table dropped early (data loss) or never dropped.
6. **No partial-failure story:** if any site is skipped, the run ends in `error` state and the mode
   is never flipped (flip requires `completed >= to_process`). There is no retry-failed-only path
   (Resume scrapes *pending DOM badges*, not server state), no rollback of already-cloned tables,
   and dismissing the error deletes the state entirely, stranding half-migrated data.
7. **`Clone_Start_Ajax` lacks guards:** doesn't reject when a clone is already RUNNING (overwrites
   state — the dead AS event had this check), when `new_mode` equals the current mode, or when
   site IDs are invalid/not in the network. `Clone_Process_Site_Ajax` trusts `reset_checks` /
   `reset_source` from each POST (JS re-sends them) instead of reading the saved state — they can
   diverge; and if state is missing it silently defaults to shared→separate and processes anyway —
   should hard-fail.
8. **Site lifecycle hooks absent:** new site created while in separate mode (no table is created —
   `wp_initialize_site`), site deleted (table never dropped, never removed from
   available-sites/state), site archived/spammed.
9. **Multisite uninstall incomplete:** `iawmlf_uninstall()` → `Migrations::down()` runs once in the
   calling context. In separate mode, per-site tables on other subsites are never dropped; clone
   state, merge counters, and per-blog migration logs are never cleaned;
   `Settings::clear_all_options()` only deletes blog-level options, not network options.
10. **AJAX registration gated by site availability:** clone endpoints register inside
    `Integrations::initialize()`, which bails when `Multisite::should_enable_site()` is false for
    the *current* site. Network-admin AJAX runs in main-site context — excluding the main site from
    available sites silently breaks the entire clone UI.
11. **No ownership/pruning answer for shared mode:** subsites' report pages show the whole
    network's links in shared mode, and after shared→separate every site starts with the full
    network's link set; nothing ever prunes rows a site doesn't use. Either add ownership (e.g. a
    `site_id` junction table) or explicitly accept + document the behaviour.

### Concrete bugs
12. `templates/admin/settings/clone-config.php:21` — malformed HTML comment `<--no-dev ... -->`
    (missing `!`), renders garbage into the DOM.
13. `src/Dashboard/Settings_Page.php:1227` — `dump( $clone_state, get_defined_vars() )` debug call
    in `render_multisite_mode_field()` (fatal in production if the dumper isn't loaded). Per
    project convention it stays until cleanup is approved — but it must be flagged for removal
    before release, along with the commented-out mock-state lines above it.
14. `admin_multisite_clone.js` `setStatus('running')`: `label.textContent = T.progressText ? '' : 'In Progress'`
    blanks the status label whenever localised strings exist — needs a proper `runningLabel` string.
15. Version skew: plugin header says `2.0.0-MS` while `IAWMLF_VERSION` is `'1.3.4'` (also used for
    asset cache-busting of the *new* JS).
16. `Migration_2::up()` condition `( $assumed !== $shared || ! exists )` re-runs `dbDelta` on the
    shared table on every activation in separate mode even when it exists; review the intent.
17. `Multisite::is_network_active()` returns plain `is_multisite()` — it does **not** check
    network activation (`is_plugin_active_for_network`). The name lies, and many gating decisions
    (settings always network-level, wizard gating, etc.) silently apply to per-site activations on
    multisite. Rename it or implement the real check — this is a semantic decision that touches
    everything.
18. Dead code decisions: `Migrate_From_Shared_To_Per_Site_Event.php` (disabled, contains a bogus
    `use function Symfony\Component\VarDumper\Dumper\esc;` import and an unbalanced
    `restore_current_blog()`), commented-out registrations in `Event_Controller` /
    `Clone_Start_Ajax`. Keep-or-delete needs an explicit call.

### Test gaps
19. No tests for: `Table_Manager` (both directions, reset/truncate options),
    `Table_Clone_State` round-trip + lifecycle, merge rules and counter/drop behaviour of
    `Merge_Duplicate_Links_Batch_Event`, recheck queueing, all three AJAX endpoints (auth, guards,
    direction selection, completion mode-flip), site lifecycle, uninstall in both modes.
    `Test_Activation_Mode_Switch_Multisite` also contains leftover commented-out skip logic and
    depends on `$GLOBALS['iawmlf_test_sites']` seeded by the bootstrap.

## 5. Plan of work (ordered)

**Phase A — settle semantics (decision points; ask the maintainer, do not assume):**
1. `is_network_active()` — rename vs real network-activation check; define behaviour matrix for
   {single-site, multisite + per-site activation, multisite + network activation}.
2. `share_settings` — implement per-site settings for separate mode (and the copy-on-clone), or
   drop the option from UI/state.
3. Shared-mode ownership — accept "all sites see all links" (document it) vs add a `site_id`
   junction table (schema migration 3).
4. Partial failure policy — retry-failed-only resume from server state; never flip mode until a
   clean completion; define what Dismiss does to half-migrated tables.

**Phase B — correctness fixes (no behaviour redesign):**
5. Balance `switch_to_blog()/restore_current_blog()` in `Table_Manager` (use try/finally).
6. Ensure shared table exists at the start of separate→shared (run/ensure Migration_2).
7. Fix migration-log blog-context consistency for `multisite_up/down` (always operate on the
   target site's log, explicitly switched).
8. Make the merge counter atomic (single SQL `UPDATE ... SET v = v - 1` on the options/sitemeta row,
   or an AS-side lock), and only drop the table from the site's own context.
9. Harden the AJAX trio: running-state guard, same-mode guard, site validation against
   `get_sites()`, read options from `Table_Clone_State` not POST, hard-fail on missing state,
   register the clone endpoints unconditionally for network admins (decouple from
   `should_enable_site()`).
10. Fix the JS `runningLabel` bug, drive Resume from server state (return remaining sites from a
    new lightweight state endpoint or localised state), fix the malformed comment in
    `clone-config.php`.

**Phase C — lifecycle:**
11. `wp_initialize_site` / `wp_uninitialize_site` handlers (create/drop per-site table in separate
    mode; clean available-sites and clone state).
12. Network-aware uninstall: iterate sites in separate mode, drop shared table per policy, delete
    network options, merge counters, clone state, per-blog migration logs.

**Phase D — finish features:**
13. Implement `share_settings` per Phase-A decision.
14. Pruning/ownership per Phase-A decision (if junction table: Migration_3, write-path updates in
    `Link_Repository`, report filtering, and clone logic can then split shared→separate precisely).

**Phase E — tests (multisite bootstrap):** cover every item in §4.19; both clone directions with
duplicates and conflicts; counter race simulation; AJAX guard matrix; uninstall both modes.
**Never skip or weaken an existing test** — if one blocks, stop and ask.

**Phase F — release hygiene (requires explicit approval for each deletion):** remove `dump()` call,
mock-state comments, decide fate of `Migrate_From_Shared_To_Per_Site_Event`, align
`IAWMLF_VERSION` with the header, changelog/readme for 2.0.0-MS.

## 6. Repo facts & working constraints

- Key namespaces: `Internet_Archive\Wayback_Machine_Link_Fixer\{Multisite, Migration, Settings, Ajax}`;
  migrations live in `Internet_Archive\Wayback_Machine_Link_Fixer_Migration`.
- Option keys all prefixed `iawmlf_`; clone state: `iawmlf_table_clone_state`; mode:
  `iawmlf_multisite_links_table_mode`; merge counters: `iawmlf_merge_pending_{site_id}`.
- PHP 7.4 minimum, WP 6.4+, WordPress Coding Standards (phpcs), Action Scheduler bundled via
  composer (`vendor/woocommerce/action-scheduler`).
- Branch: `multisite_update-migrations-for-multsite-mode`; default branch for PRs: `trunk`.
- Tests: PHPUnit with `tests/bootstrap.php` (single-site) and `tests/bootstrap-multisite.php`
  (multisite; seeds `$GLOBALS['iawmlf_test_sites']` with 'garren' and 'dexter' sites).
- **Environment constraints (hard):** composer/npm/mysql CLIs are not available outside the dev
  container — do not run them; do not run git/gh commands; do not access local dev URLs; JS builds
  (`assets/js/src` → `assets/js/build`) happen in the container.
- **Process constraints (hard):** one change at a time, wait for the user to test; never delete
  debug statements (`dump()`, `dd()`) or code while debugging — comment out and clean up only when
  approved; when a decision has multiple viable options, ask instead of choosing; assume your code
  (not the test) is wrong when a test fails.
