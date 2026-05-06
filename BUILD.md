# BundlePilot — Build & Release Guide

This document covers releasing a new version of BundlePilot via GitHub Actions.
Every run produces **two ZIPs** — one for Freemius (WordPress.org +
addoneplugins.com) and one for the WooCommerce Marketplace.

---

## One-time setup

### 1. Push the plugin to a GitHub repository

If you haven't already:

```bash
cd "D:\Inshalytics\Plugins\Plugins - Final\aop-bundle-builder"
git init
git add .
git commit -m "Initial BundlePilot release v1.0.0"
git branch -M main
git remote add origin git@github.com:addoneplugins/bundle-pilot.git
git push -u origin main
```

### 2. Add the Freemius API token as a GitHub secret

The workflow uploads to Freemius using a bearer token. Get it from the
Freemius dashboard:

1. Log in to <https://dashboard.freemius.com>
2. Click your avatar (top-right) → **My Profile**
3. Scroll to **API** → copy the **Bearer Token**

Add it to the repo:

1. Open the GitHub repo → **Settings** → **Secrets and variables** → **Actions**
2. Click **New repository secret**
3. Name: `FREEMIUS_BEARER_TOKEN`
4. Value: paste the token
5. **Add secret**

The workflow file references it as `${{ secrets.FREEMIUS_BEARER_TOKEN }}`.

---

## Releasing a new version

### Option A — Manual dispatch (recommended for first deploy)

1. Bump the version number everywhere it appears:
   - `bundlepilot.php` header (`Version: 1.0.1`)
   - `bundlepilot.php` constant (`define( 'AOP_BB_VERSION', '1.0.1' );`)
   - `readme.txt` (`Stable tag: 1.0.1`)
   - `changelog.txt`
2. Commit and push.
3. On GitHub, go to **Actions** → **Build & Deploy BundlePilot** → **Run workflow**.
4. Fill in:
   - **Release version**: `1.0.1` (must match what's in the plugin headers)
   - **Freemius release mode**:
     - `pending` — uploaded, hidden from customers, awaits manual promotion
     - `beta` — visible to beta testers only
     - `released` — live to all customers
   - **Skip Freemius deploy** — check this if you only want the ZIPs without uploading
5. Click **Run workflow**.

### Option B — Tag push (faster for routine releases)

```bash
git tag v1.0.1
git push origin v1.0.1
```

Triggers the workflow automatically with `release_mode = pending` (so nothing
goes live without you promoting it manually in the Freemius dashboard).

---

## What the workflow produces

After a successful run, two artifacts are attached to the workflow run page
(retention: 30 days):

| Artifact | Folder structure | Contains | Use for |
|---|---|---|---|
| `bundlepilot-freemius-<version>` | `bundlepilot/` | + freemius/ SDK | Auto-uploaded to Freemius. Freemius then deploys to wp.org (premium-stripped) and to addoneplugins.com (full premium build). |
| `bundlepilot-wc-<version>` | `bundlepilot/` | – freemius/ SDK | Manual upload to WooCommerce Marketplace vendor dashboard. |

The Freemius ZIP gets pushed to the Freemius API automatically (unless
`skip_freemius_deploy` was checked). The WC Marketplace ZIP needs to be
**downloaded from the workflow artifacts** and uploaded manually to your
WC Marketplace vendor dashboard for each release.

---

## How the WC build differs from the Freemius build

The two ZIPs are built from the same source with one targeted change:

- The `freemius/` SDK directory is omitted from the WC build.
- `define( 'AOP_BB_LICENSE_MODE', 'freemius' )` is rewritten to
  `define( 'AOP_BB_LICENSE_MODE', 'unlocked' )` in the main plugin file.

In `unlocked` mode, `AOP_BB_License_Manager::can_use()` returns `true` for
every plan-gated feature. There's no licensing check because WC handles
licensing externally via the WooCommerce.com Helper. All features —
unlimited bundles, tiered pricing, white label, templates, import/export,
webhooks, role visibility — are enabled out of the box.

The PHP code that defines `bbfw_fs()` stays in the main file but lives
inside an `if ( 'freemius' === AOP_BB_LICENSE_MODE && file_exists(...) )`
block, so it's inert in the WC build. No errors, no orphan calls.

---

## Manual local builds (if you need a ZIP without GitHub Actions)

The workflow's logic can be reproduced manually on Windows / Mac / Linux:

```bash
# From the plugin root.

# Freemius ZIP (everything as-is).
mkdir -p dist-freemius/bundlepilot
rsync -a --exclude='.git*' --exclude='.github' --exclude='node_modules' \
         --exclude='dist*' --exclude='*.zip' --exclude='make-pot.php' \
         --exclude='README.md' --exclude='BUILD.md' \
         ./ dist-freemius/bundlepilot/
cd dist-freemius && zip -rq ../bundlepilot.zip bundlepilot/ && cd ..

# WC Marketplace ZIP (no freemius/, license mode unlocked).
mkdir -p dist-wc/bundlepilot
rsync -a --exclude='.git*' --exclude='.github' --exclude='node_modules' \
         --exclude='dist*' --exclude='*.zip' --exclude='make-pot.php' \
         --exclude='README.md' --exclude='BUILD.md' \
         --exclude='freemius' \
         ./ dist-wc/bundlepilot/
sed -i "s/define( 'AOP_BB_LICENSE_MODE', 'freemius' )/define( 'AOP_BB_LICENSE_MODE', 'unlocked' )/" \
    dist-wc/bundlepilot/bundlepilot.php
cd dist-wc && zip -rq ../bundlepilot-pro.zip bundlepilot/ && cd ..
```

On Windows without bash, use WSL or PowerShell equivalents
(`Compress-Archive`, `Get-Content -Replace -Out-File`, etc.).

---

## Regenerating the translation template

Any time you add or change translatable strings, regenerate
`languages/bundlepilot.pot`:

```bash
php make-pot.php
```

The script scans all PHP files (excluding `freemius/`, `vendor/`, etc.)
and rebuilds the POT. It's a build-time tool — `make-pot.php` is
intentionally excluded from both shipped ZIPs.

---

## Troubleshooting

**"Version 1.0.1 already exists on Freemius"** — Freemius rejects
duplicate version uploads. Either bump the version in the plugin
headers or delete the old tag in the Freemius dashboard first.

**"API connection failed"** — usually means `FREEMIUS_BEARER_TOKEN`
is missing or wrong. Re-copy it from the Freemius profile page and
update the GitHub secret.

**WC build still contains freemius/** — the verification step would
fail the workflow before this happens. If somehow it slips through,
the rsync `--exclude='freemius'` line is the single source of truth;
make sure it's still in the workflow YAML.
