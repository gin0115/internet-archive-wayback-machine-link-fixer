# Handover — Multisite Mode Branch

**Branch:** `multisite_update-migrations-for-multsite-mode` (PRs target `trunk`)
**Date:** 2026-06-11
**Companion doc:** `MULTISITE_PLAN_PROMPT.md` (full context, gap list, and phased plan — read that first).

## Where things stand

The two-mode multisite storage system (shared network table vs per-site tables) is largely built
and wired end-to-end, but **not finished or release-ready**. The mode-switch (clone) flow works as
a happy path driven from the network settings page; failure handling, settings sharing, lifecycle
hooks, and most tests are missing. There are known bugs (blog-context leak, non-atomic merge
counter, malformed template comment, JS label bug) — all enumerated with file references in
`MULTISITE_PLAN_PROMPT.md` §4.

## Current state of the working tree (uncommitted)

Modified: main plugin file, `Migration_1`, `Migrations`/`Abstract_Migration`, `Settings`,
`Multisite`, `Settings_Page`, `Setup_Wizard`, `Dashboard_Page`, `Report_Page`, `Report_Table`,
`Ajax_Controller`, `Event_Controller`, `Integrations`, `Environmental`, settings SCSS/JS, composer.lock.

New (untracked): `migrations/Migration_2.php`, `src/Multisite/Table_Manager.php`,
`src/Multisite/Table_Clone_State.php`, `src/Multisite/Event/` (3 events),
`src/Ajax/Clone_{Start,Process_Site,Dismiss}_Ajax.php`, `assets/js/src/admin_multisite_clone.js`,
`templates/admin/settings/clone-{config,status}.php`,
`tests/Multisite/Test_Activation_Mode_Switch_Multisite.php`.

## Architecture in one paragraph

Mode lives in network option `iawmlf_multisite_links_table_mode`; all table access routes through
`Settings::get_link_table_name()`. Switching modes is a data migration run as a JS-driven
sequential AJAX batch (one site per request: `Clone_Start_Ajax` → N× `Clone_Process_Site_Ajax` →
`Clone_Dismiss_Ajax`), with resumable progress persisted in `Table_Clone_State` (network option
`iawmlf_table_clone_state`). Shared→separate copies the whole shared table to each site (no
site-ownership data exists). Separate→shared inserts non-duplicates directly, then merges
duplicates via Action Scheduler (`Merge_Duplicate_Links_Batch_Event`, 50-row chunks, per-site
countdown counter `iawmlf_merge_pending_{id}`; last chunk drops the subsite table) and queues
rechecks. The earlier all-Action-Scheduler migration event
(`Migrate_From_Shared_To_Per_Site_Event`) is disabled/commented out, kept for reference.

## Deliberate quirks — do not "fix" without understanding

- The mode `<select>` is **disabled by JS** when changed so the normal settings-form save can't
  persist an un-migrated mode; the server flips the mode only when the clone completes
  (`Clone_Process_Site_Ajax`).
- `dump( $clone_state, ... )` at `src/Dashboard/Settings_Page.php:1227` and the commented mock-state
  lines above it are **intentional debug scaffolding** — project rule: never delete debug
  statements without explicit approval.
- The migration log is intentionally read per-blog (`Settings::migrations( true )`) when managing
  per-site tables — though the read/write context is currently inconsistent (see plan §4.4).

## Next actions (suggested order)

1. Get maintainer decisions on the four Phase-A questions in the plan (network-active semantics,
   `share_settings`, shared-mode ownership/pruning, partial-failure policy). **Ask — don't assume.**
2. Phase-B correctness fixes (restore_current_blog, shared-table ensure, AJAX guards, atomic
   counter, JS fixes).
3. Then lifecycle, features, tests, cleanup per the plan.

## Environment rules for whoever picks this up

- composer/npm/mysql run **only inside the dev container** — not from this CLI.
- No git/gh commands from the terminal; no pushes to `trunk`/protected branches.
- Local dev URLs are not reachable — ask the user to test in-browser.
- One change at a time; user tests between changes. Tests are never skipped or rewritten to match
  broken code. Multi-option decisions get asked, not made.
