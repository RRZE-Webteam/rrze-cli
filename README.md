# RRZE-CLI

WP-CLI extension for RRZE's CMS management.

## Requirements

-   PHP >= 8.2
-   WP-CLI >= 2.11.0
-   PHP zip extension for migration packages

## Tests

Run `composer test:install` once, then `composer test:unit`. Test dependencies are installed separately under `tests/vendor`; the bundled plugin dependencies are not changed. Integration tests use disposable WordPress copies and new databases on the local MySQL server. See [Testing migrations](docs/testing.md) (German) for setup, isolation, coverage and limitations.

## Migration

The safety requirements and remaining acceptance work are documented in [Migration scope and safety rules](docs/migration-scope.md) (German).

An import creates a **new site in a multisite**. Existing sites block the import, including empty, archived, disabled or deleted-flagged sites. Delete an old destination manually in Network Admin before importing. Single-site destinations and direct table/user imports are rejected.

### Private package storage

Configure `RRZE_MIGRATION_RUN_DIR` in the source and destination installations, or pass `--run-dir` to the export/import command:

```php
define('RRZE_MIGRATION_RUN_DIR', '/srv/private/rrze-migrations');
```

The directory must be outside WordPress and `wp-content`, owned by the CLI user with permissions `0700`, and not published by another web-server configuration. Its parent must exist. ZIP exports create a new subdirectory containing a UTC timestamp, source site ID and random suffix; the ZIP has permissions `0600`. Import runs retain their existing independent run-ID directories:

```text
/srv/private/rrze-migrations/
  export-20261007-103000-site-5-<random>/website.zip
  incoming/website.zip                 # optional location for packages transferred from another server
  <run-id>/package.zip
           run.json
           active.lock
           media.json
           media-files.txt
```

Relative import paths resolve against this private root, never against the WordPress directory. Use the export's displayed absolute path (or its path relative to this root) on the same system, or transfer the ZIP into a private location on the destination, for example `incoming/website.zip`. Absolute input paths outside WordPress and `wp-content` are also accepted. Webroot packages are rejected; move old packages to private storage yourself before importing. The importer neither moves nor deletes the original. A dry-run with an absolute private input needs no configured root and creates no persistent storage. Exports and imports are retained until the administrator removes them.

### Export

Run from the source WordPress directory:

```sh
wp rrze-migration export all website.zip --url=https://source.example.test/site/
```

On Multisite, `--site-id` can select the source instead of `--url`:

```sh
wp rrze-migration export all website.zip --site-id=5
```

The ID must identify an existing website in this installation. It takes precedence over the initial `--url` context. If needed, the command starts a fresh WP-CLI process for that site's registered address so its plugins, user roles and upload settings load correctly, and verifies the loaded site ID before export. Invalid IDs and incorrect routing stop the export. The direct `export all` command remains suitable for scripts and does not prompt; use the wizard for source confirmation. The low-level `export tables` and `export users` commands continue to use `--url`.

The output argument is a filename without a directory; `.zip` is appended if missing. Every export gets its own private subdirectory, so repeated filenames keep earlier exports intact. The wizard displays the full destination before approval, and the command prints it after success. Cancellation creates no export directory; handled failures remove the incomplete export. Temporary JSON, CSV and SQL files are stored in a private directory and removed after success or a handled failure.

Uploads containing server configuration or executable files, such as `.htaccess` or `index.php`, block export and the error names the offending path. To omit a plugin's backup or temporary directory deliberately, use an explicit directory exclusion:

```sh
wp rrze-migration export all website.zip --url=https://source.example.test/site/ --exclude-upload-dirs=wp-migrate-db
```

`--exclude-upload-dirs` accepts comma-separated existing directories relative to that site's upload root, without wildcards. For the modern network main site, `sites` is automatically excluded because it contains other websites' media. Each selected directory and its descendants are omitted; neighbouring names such as `wp-migrate-db-other` remain included. Source files are neither deleted nor modified. The omission is recorded in `site.json` and shown in the import plan; excluded files are not transferred or verified. Do not remove source protection files just to satisfy the archive checks.

Core tables and site-owned custom tables of a subsite are selected by default. For a main site, the default includes only its core tables: its base prefix also matches global and other-site tables. Additional main-site tables require explicit selection and an administrative check that they belong exclusively to that site.

```sh
wp rrze-migration export all website.zip --url=https://source.example.test/ --custom-tables=wp_local_data
```

`--custom-tables` adds to the default selection. `--tables` selects an explicit list, but a full ZIP package must still contain every core site table. Global user/network tables and other sites' tables cannot be selected. Separate SQL and CSV exports remain available through `export tables` and `export users`; those low-level commands still use their explicit output paths, so choose an absolute private path for sensitive data.

### Interactive wizard

Run the wizard from the relevant WordPress directory in an interactive terminal:

```sh
wp rrze-migration wizard export --url=https://source.example.test/site/
wp rrze-migration wizard export --site-id=5
wp rrze-migration wizard import
```

The export wizard first asks for the source website ID on Multisite, defaulting to the current website; `--site-id` supplies this answer directly. It then displays `ID X | URL: …` for the selected website and asks `Export this website (yes/no) [no]`. Only `yes` proceeds to the private migration directory and package options. It shows the selected tables, includes uploads by default, and still requires the source URL plus final confirmation before creating an archive. Single-site exports confirm the current website without an ID selection. Both operations use `RRZE_MIGRATION_RUN_DIR` as the default private directory. The import wizard asks for the package, new destination URL and numeric user-reference fields. Its default action is a read-only preview. To execute, choose `import`, review the plan, type the complete normalized destination URL and answer `yes` to the final confirmation. Empty confirmation means cancellation; `!quit`, end of input and supported cancellation signals also stop the wizard.

For import, available ZIPs appear in a numbered list with their relative paths, filesystem modification times (UTC) and sizes. Enter a number or type a private relative/absolute path directly; no package is selected by default. Discovery includes export directories, incoming packages and retained import copies, without following symbolic links. It displays up to 50 files, newest modification first, and searches at most 5000 entries through three subdirectory levels. An incomplete list is marked; manual paths remain available. Listing a file does not validate its contents or indicate that a previous import succeeded. The selected path and package still undergo the normal checks. This uses the existing terminal implementation without another prompt dependency.

The wizard uses the same export/import implementation and preflight as the direct commands. Import approval applies to the preserved package copy and the displayed plan. A changed site allocation, table mapping or user action during review stops execution before site creation. Cancelled imports can retain a private journal and package with status `failed`, no site ID and a completed cleanup checkpoint. Use the existing status command to inspect them.

Piped input/output, `--yes` and `--quiet` are rejected. The wizard accepts `--site-id` only for export. Scripts continue to use `export all`, `import all --dry-run --format=json` and `import all` with explicit arguments. See [Wizard usage](docs/migration-wizard.md) (German) for the guided workflow and cancellation behavior.

The export wizard asks for optional directory exclusions and lists them before confirmation. The import wizard displays recorded exclusions and requires separate consent before importing such a package.

### Preflight and dry-run

Validate a package and display the planned changes before importing:

```sh
wp rrze-migration import all incoming/website.zip --new_url=https://target.example.test/new-site/ --dry-run
wp rrze-migration import all incoming/website.zip --new_url=https://target.example.test/new-site/ --dry-run --format=json
```

Both commands use the same preflight as execution. The plan shows the source and destination, estimated site ID, table mapping, existing/new WordPress user actions, media files and destination storage. A dry-run creates only private temporary extraction files and removes them afterwards; it does not create sites, users, tables or destination uploads. Normal WordPress/plugin bootstrap still runs. JSON plans include SSO logins and local paths; treat saved plans as internal operational data.

The next site ID is an estimate, not a reservation. Execution rebuilds the plan under the migration lock and checks the allocated ID before WordPress initializes it. Existing tables, upload directories (even empty ones), links or membership metadata for that ID block the import. Nothing is automatically removed. A conflict discovered after the site row was inserted retains that incomplete row for manual inspection.

Packages use format version 2 in `site.json`, with sizes and SHA-256 hashes for `users.csv`, `tables.sql` and `media.json`. The media inventory records the source directory, base URL, relative filenames, sizes, hashes and exclusions. Media bytes are never included in the ZIP. Version 1 packages require a new export. Unsupported/unversioned packages must be exported again. Checksums detect transfer damage and changed contents; they do not authenticate the producer or make arbitrary SQL safe. Unexpected entries, path collisions, links, encrypted archives and server-executable upload files are rejected before import.

Current bounds: 2 GiB ZIP, 4 GiB expanded data, 100,000 entries, 64 MiB SQL, 16 MiB CSV, 4 MiB site metadata, 64 MiB media inventory (up to 100,000 files; media bytes are external) and 10,000 users. Entries larger than 1 MiB must not exceed a 200:1 compression ratio. Available PHP memory, temporary storage and upload storage are checked conservatively; these checks cannot reserve capacity or determine free space on the database server.

Destination preflight requires explicit database/schema grants sufficient for migration. Grants available only through roles or individual tables are not yet supported. The destination must have a dedicated standard multisite media root under `wp-content/uploads/sites/<ID>`. Filters that preserve that root are supported, including subdirectory filters. Custom/shared destination roots and legacy `ms-files.php` destinations still require an adapter; rsync is not a bypass for ownership checks. Source upload options are reset on the new site; its effective directory and URL are resolved in its own WordPress context.

### External media transfer (required workflow)

Freeze source writes before exporting and retain a matching media snapshot, especially before deleting a source website in the same installation. The ZIP is not a media backup. Export and import have no `--uploads` or `--skip-uploads` option anymore.

The data import maps source media URLs to the new site's actual media URL (including its new site ID), reserves an empty site-owned media directory, and ends with `media_pending`. It prints rsync preview/copy instructions without executing them or managing SSH credentials. To regenerate commands on the destination:

```sh
wp rrze-migration media plan RUN_ID --source-host=operator@source.example --source-dir=/srv/snapshots/site-media
# Or explicitly use a local/mounted snapshot:
wp rrze-migration media plan RUN_ID --source-dir=/mnt/snapshots/site-media
```

Run the displayed preview, review it, then run the copy command. Remote commands require rsync 3.0+ on both hosts (`--protect-args`). The commands use a private NUL-delimited file list, do not follow/copy symlinks, and use `--ignore-existing` without deletion. A changed existing target file must be inspected and repaired deliberately within the new site's directory; repeating the suggested command will not overwrite it.

```sh
wp rrze-migration media verify RUN_ID
wp rrze-migration status RUN_ID --format=json
```

Verification checks the original retained package, installation/network/site ownership, unchanged effective upload location, exact inventory, file sizes/hashes and attachment references/URLs. Missing, additional, changed or linked files leave the run `media_pending`. Successful verification changes it to `completed`; repeating verification is supported and a failed recheck revokes the previous media success. No website data are reimported or rewritten by this command. Excluded directories remain outside the migration scope.

For protected media, configure rrze-ac and the web-server rules **before** transfer. Verification checks rrze-ac configuration and additionally requires `--access-checked`, which records the administrator's confirmation of unauthorized and authorized HTTP access tests. It does not claim to have performed those HTTP tests automatically. See [Media transfer](docs/migration-media.md) for details and recovery.

### Import

Run from the destination WordPress directory. Use the global `--url` to select the destination network context when needed.

```sh
wp rrze-migration import all incoming/website.zip --new_url=https://target.example.test/new-site/ --uid_fields=_fixture_user --run-dir=/srv/private/rrze-migrations
```

User matching uses the exact SSO `user_login`; the company email must agree. Conflicting logins/emails, duplicate CSV identities and unknown destination roles stop the import before a new site is created. New WordPress users receive a fresh random local password; this does not provision an SSO account. Existing global user profiles, passwords and memberships on other sites are preserved. Only the new site's membership is added.

User CSV exports exclude passwords, reset keys, application passwords, sessions and global permission fields, including attempts to add these through export filters. Legacy credential fields inside a supported versioned package are discarded on import. New users receive the supported standard profile fields; arbitrary custom user metadata and former import hooks are not replayed.

Actual imports require a private persistent `--run-dir` outside web roots, or `RRZE_MIGRATION_RUN_DIR` in the destination configuration. Each run retains its validated input package and an atomic checkpoint journal without credentials. Dry-runs create no journal directory; relative input paths still require the private root to locate the package. See [Run status and recovery](docs/migration-recovery.md) (German) for setup, interruption handling and retention.

```sh
wp rrze-migration status RUN_ID --run-dir=/srv/private/rrze-migrations --format=json
```

Imports sharing the database and base prefix run one at a time, including imports to different destination URLs. Each created site is tagged with its run ID and ownership is checked between steps. Before reporting the site-data import as complete, the importer verifies destination tables, URLs, user mappings and existing participant profiles. The overall run remains `media_pending` until the separate media verification succeeds. A running step without a completion checkpoint may have partially or fully executed; individual steps are never replayed automatically.

Database export/import and URL replacement run in child processes. A failed command stops the migration with a nonzero status. A partially created site is retained for inspection and must be deleted manually before a retry. The import never removes sites or global users automatically.

### Compatibility and remaining work

- `import tables`, `import users` and `posts update_author` cannot be used to mutate an existing site directly.
- `--usersuffix` is rejected because SSO identities must remain unchanged.
- `--plugins` and `--themes`, including legacy packages containing their code, are rejected. Provide dependencies separately in the destination.
- `--mysql-single-transaction` is rejected: wrapping a dump containing DDL does not make the migration atomic.

Use packages from controlled exports only. Current SQL table checks are not a sandbox for arbitrary SQL. Full SQL isolation, extension compatibility, concurrency with external writers and real SSO acceptance remain part of the following work packages. Recovery uses the retained package for a fresh import after manual site deletion; it does not restore an independently deleted old site or roll back global tables. A successful dry-run does not execute SQL and cannot guarantee a successful import. The site-data import leaves media pending; the separate `media verify` command checks the external transfer. Historical media URL aliases, arbitrary plugin references and real HTTP access still require operational review.
