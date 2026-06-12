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

Replace `BRANCH` with the branch the blueprints live on:

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/gin0115/internet-archive-wayback-machine-link-fixer/BRANCH/blueprints/single-site-qa.json
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/gin0115/internet-archive-wayback-machine-link-fixer/BRANCH/blueprints/multisite-shared-qa.json
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/gin0115/internet-archive-wayback-machine-link-fixer/BRANCH/blueprints/multisite-separate-qa.json
```

## How the plugin gets in

Each blueprint installs the plugin from the GitHub **release asset** directly:

```
https://github.com/gin0115/internet-archive-wayback-machine-link-fixer/releases/download/qa-preview/internet-archive-wayback-machine-link-fixer.zip
```

fetched through Playground's CORS proxy (the blueprints set
`"corsProxy": "https://cors.wordpress.net/proxy.php"` — the old
github-proxy.com service was shut down in early 2026).

The existing `.github/workflows/release.yml` builds and attaches that zip
automatically whenever a release is created — so the QA flow is:

1. Push the branch.
2. Create a (pre)release tagged `qa-preview` targeting it — the workflow
   builds `internet-archive-wayback-machine-link-fixer.zip` (i18n + no-dev
   vendor + built assets) and attaches it.
3. Share the launch links above.

To point QA at a newer build, either re-cut the `qa-preview` release or
change the `release=` value in the blueprints.

## Caveats

- Playground is **SQLite** behind a MySQL translation layer — fine for UI/QA
  flows, but the raw-SQL clone paths run through the translator, not real
  MySQL. Anything suspicious should be re-checked on a real MySQL install.
- `features.networking` is enabled so the Wayback Machine API calls work,
  but Playground's network access is proxied and can be slow or limited.
