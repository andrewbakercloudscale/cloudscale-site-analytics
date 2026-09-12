# Two copies of this plugin would kill the site — how to fix it

Status: **not fixed.** This is a latent fault with a known fix; nothing has been changed
in the plugin yet. Written 2026-09-12 after the same fault took a live site down in
CloudScale Backup & Restore.

This file lives in `docs/`, which `build.sh` excludes from the zip. Note that `*.md` is
**not** excluded generally — only `WORKING-NOTES.md` is, by name — so a doc added at the
repo root *would* ship to WordPress.org. Keep notes like this in `docs/`.

---

## 1. What happened elsewhere, so this is not hypothetical

On 2026-09-12 a test site running CloudScale Backup & Restore was killed by activating a
second copy of that plugin:

```
PHP Fatal error: Cannot redeclare csbr_backup_dir()
  previously declared in plugins/cloudscale-backup/cloudscale-backup.php:151
  in plugins/cloudscale-backup-restore/cloudscale-backup.php:132
```

The mechanism was:

1. the plugin exists under **two different folder names** — `cloudscale-backup` as deployed
   to our estate, `cloudscale-backup-restore` as published on WordPress.org;
2. WordPress identifies a plugin by its **folder**, so it saw two unrelated plugins,
   offered both, and warned about nothing;
3. the plugin declares a large number of **top-level functions with no guards**, so loading
   the second copy in the same request as the first hit a redeclaration and PHP fatalled.

Nothing about it was unlucky. Activating the second copy was certain to fail.

## 2. Where this plugin stands

Measured in this repo on 2026-09-12, against the shipped zip:

| | |
|---|---|
| shipped PHP files | 28 |
| top-level functions in them | **190** |
| of those, `cspv_`-prefixed | 189 (the exception is `mmdb_autoload`) |
| duplicate/redeclare guard | **none** |
| WordPress.org slug | `cloudscale-site-analytics` |
| folder deployed to our estate | `cloudscale-site-analytics` |

**The blast radius is the same as the plugin that died — 190 redeclarations waiting to
happen. What is missing is the trigger.** Because the WordPress.org slug and our deployed
folder name are identical, installing from the directory *updates the same folder* instead
of creating a second copy. That is the only reason this has never bitten.

### Why "the slugs match" is not a fix

It is a coincidence of naming, not a control, and several ordinary things defeat it:

* a zip extracted by hand into `wp-content/plugins/` under any other folder name;
* a re-upload that WordPress names `cloudscale-site-analytics-1`;
* a copy left behind by a migration tool, a staging sync, or a host's "restore previous
  version" feature;
* renaming the deployed folder for any reason in future — at which point the trigger
  appears and this becomes the backup plugin's bug exactly.

Also worth knowing: WordPress.org currently serves **2.9.490** while production runs
**2.9.500+**, so a directory install is a downgrade as well.

## 3. The fix

Five lines at the top of `cloudscale-site-analytics.php`. `CSPV_VERSION` is defined on
**line 22**, before any `require` and before any function declaration, which is exactly the
point where a second copy can still be stopped harmlessly.

Insert immediately **before** the existing `define( 'CSPV_VERSION', ... );`:

```php
/*
 * Refuse to load a second copy of this plugin.
 *
 * WordPress identifies a plugin by its FOLDER, so the same plugin in two directories
 * — a hand-extracted zip, a "-1" suffix from a re-upload, a staging sync leftover —
 * is two unrelated plugins to it, and it will happily activate both. This file then
 * re-declares 190 top-level functions and the request dies with
 * "Cannot redeclare cspv_...". The identical fault killed a live site running
 * CloudScale Backup & Restore on 2026-09-12; see docs/duplicate-copy-fatal.md.
 *
 * Returning here turns that fatal into a visible no-op: the copy that loaded first
 * keeps working, the second does nothing, and the notice says which is which.
 */
if ( defined( 'CSPV_VERSION' ) ) {
    add_action(
        'admin_notices',
        static function () {
            if ( ! current_user_can( 'activate_plugins' ) ) {
                return;
            }
            echo '<div class="notice notice-error"><p><strong>'
                . esc_html__( 'CloudScale Site Analytics is installed twice.', 'cloudscale-site-analytics' )
                . '</strong> '
                . esc_html(
                    sprintf(
                        /* translators: %s: plugin folder path of the copy that is running. */
                        __( 'Only the copy in %s is running. Delete the other copy from Plugins — leaving both installed risks a fatal error on the next activation.', 'cloudscale-site-analytics' ),
                        defined( 'CSPV_PLUGIN_DIR' ) ? CSPV_PLUGIN_DIR : 'wp-content/plugins'
                    )
                )
                . '</p></div>';
        }
    );
    return;
}
```

`return` at the top level of a plugin file ends the include and nothing further is
declared, so the guard must sit above the first `define()` and above every `require`.

### Do NOT wrap the 190 functions in `function_exists()`

It is the obvious-looking alternative and it is worse than the fatal. With guards, both
copies load and you get a **mixture** of two versions — 2.9.490's function here,
2.9.501's there, sharing one set of options and one database schema. That produces wrong
numbers rather than an error, and it is close to undebuggable. A fatal is honest; a silent
version blend is not.

"First copy wins, and the admin is told" is the behaviour to aim for.

## 4. Verifying it

1. **Reproduce first, so you know the test is real.** Copy the plugin folder to
   `wp-content/plugins/cloudscale-site-analytics-dupe/`, activate it, and confirm the site
   fatals with `Cannot redeclare cspv_...`. A guard that is never seen to prevent anything
   has not been shown to work.
2. Apply the guard, activate the duplicate again, and confirm: the site stays up, the
   original copy still works, and the duplicate shows the notice naming the running folder.
3. Deactivate and delete the duplicate; confirm the notice disappears.
4. Run `bash build.sh` — the standards and Plugin Check gates must stay clean, and the
   `esc_html__`/`esc_html` calls above exist to keep them so.

## 5. The wider fix, which is not this plugin's to make

The same guard belongs in **CloudScale Backup & Restore**, where the trigger is live rather
than latent — that plugin has 203 top-level functions and a genuinely divergent slug. Two
of the five plugins (`cloudscale-cleanup`, `cloudscale-cyber-devtools`) are not published on
WordPress.org at all, so they have no directory-install trigger; `cloudscale-seo-ai-optimizer`
declares **one** top-level function and is effectively immune by architecture, which is the
better long-term answer than any guard.

A build-time check in the house style would catch the whole class: assert that the folder a
plugin deploys to matches the slug it publishes under, and fail the build when they diverge.
