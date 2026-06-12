# WordPress Playground QA Blueprints

One-click QA environments on [WordPress Playground](https://wordpress.org/playground/) —
no install, runs entirely in the browser, auto-logged-in as `admin`.

## The three environments

| Blueprint | What you get |
|---|---|
| `single-site-qa.json` | Single site, onboarding done, **150 links** in mixed states (broken / archived / excluded) + 3 posts containing them |
| `multisite-shared-qa.json` | Multisite (main + garren + dexter), network-activated, **shared** links table with 60 links, a post on every site |
| `multisite-separate-qa.json` | Multisite in **separate** mode: per-site tables (20 unique + 5 overlapping URLs each) — ready to QA the separate→shared merge, duplicate handling included |

## Launch links

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/gin0115/internet-archive-wayback-machine-link-fixer/multisite_update-migrations-for-multsite-mode/blueprints/single-site-qa.json
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/gin0115/internet-archive-wayback-machine-link-fixer/multisite_update-migrations-for-multsite-mode/blueprints/multisite-shared-qa.json
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/gin0115/internet-archive-wayback-machine-link-fixer/multisite_update-migrations-for-multsite-mode/blueprints/multisite-separate-qa.json
```

raw.githubusercontent caches by path for ~5 minutes and ignores query
strings — after editing a blueprint, either wait it out or share a
commit-pinned link (swap the branch segment for the commit SHA).

## How the plugin gets in

Each blueprint installs the plugin from a zip **committed in this repo** at
`blueprints/dist/internet-archive-wayback-machine-link-fixer.zip`, served via
raw.githubusercontent.com (which sends proper CORS headers). The URL is
pinned to the commit that contains the zip.

Why not the GitHub release asset? Playground's browser fetch needs CORS:
`plugin-proxy.php` no longer supports release assets, github-proxy.com was
shut down in early 2026, and `cors.wordpress.net` refuses to follow GitHub's
release-download redirect. raw.githubusercontent is the reliable path.

### Refreshing the QA build

1. Cut/re-cut the `qa-preview` release on the branch — the existing
   `.github/workflows/release.yml` builds and attaches the canonical zip
   (i18n + no-dev vendor + built assets).
2. Download that asset and commit it over `blueprints/dist/…zip`
   (`git add -f` — the `dist` dir is gitignored).
3. Update the pinned commit SHA in the three blueprints' `installPlugin` URL.

## Caveats

- Playground is **SQLite** behind a MySQL translation layer — fine for UI/QA
  flows, but the raw-SQL clone paths run through the translator, not real
  MySQL. Anything suspicious should be re-checked on a real MySQL install.
- `features.networking` is enabled so the Wayback Machine API calls work,
  but Playground's network access is proxied and can be slow or limited.
