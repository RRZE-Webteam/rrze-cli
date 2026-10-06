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

### Export

Run from the source WordPress directory:

```sh
wp rrze-migration export all website.zip --url=https://source.example.test/site/ --uploads
```

Existing output files are never replaced. Temporary JSON, CSV and SQL files are stored in a private directory and removed after success or a handled failure.

Uploads containing server configuration or executable files, such as `.htaccess` or `index.php`, block export and the error names the offending path. To omit a plugin's backup or temporary directory deliberately, use an explicit directory exclusion:

```sh
wp rrze-migration export all website.zip --url=https://source.example.test/site/ --uploads --exclude-upload-dirs=wp-migrate-db
```

`--exclude-upload-dirs` accepts comma-separated existing directories relative to that site's upload root, without wildcards. Nothing is excluded by default. Each selected directory and its descendants are omitted; neighbouring names such as `wp-migrate-db-other` remain included. Source files are neither deleted nor modified. The omission is recorded in `site.json` and shown in the import plan; excluded files are not transferred or verified. Do not remove source protection files just to satisfy the archive checks.

Core tables and site-owned custom tables of a subsite are selected by default. For a main site, the default includes only its core tables: its base prefix also matches global and other-site tables. Additional main-site tables require explicit selection and an administrative check that they belong exclusively to that site.

```sh
wp rrze-migration export all website.zip --url=https://source.example.test/ --custom-tables=wp_local_data --uploads
```

`--custom-tables` adds to the default selection. `--tables` selects an explicit list, but a full ZIP package must still contain every core site table. Global user/network tables and other sites' tables cannot be selected. Separate SQL and CSV exports remain available through `export tables` and `export users`.

### Interactive wizard

Run the wizard from the relevant WordPress directory in an interactive terminal:

```sh
wp rrze-migration wizard export --url=https://source.example.test/site/
wp rrze-migration wizard import
```

The export wizard shows the selected source and tables, includes uploads by default, and requires the source URL plus explicit confirmation before creating a new archive. The import wizard asks for the package, new destination URL and numeric user-reference fields. Its default action is a read-only preview. To execute, choose `import`, provide the private run directory, review the plan, type the complete normalized destination URL and answer `yes` to the final confirmation. Empty confirmation means cancellation; `!quit`, end of input and supported cancellation signals also stop the wizard.

The wizard uses the same export/import implementation and preflight as the direct commands. Import approval applies to the preserved package copy and the displayed plan. A changed site allocation, table mapping or user action during review stops execution before site creation. Cancelled imports can retain a private journal and package with status `failed`, no site ID and a completed cleanup checkpoint. Use the existing status command to inspect them.

Piped input/output, `--yes` and `--quiet` are rejected. Scripts continue to use `export all`, `import all --dry-run --format=json` and `import all` with explicit arguments. See [Wizard usage](docs/migration-wizard.md) (German) for the guided workflow and cancellation behavior.

When uploads are enabled, the export wizard also asks for optional directory exclusions and lists them before confirmation. The import wizard displays recorded exclusions and requires separate consent before importing such a package.

### Preflight and dry-run

Validate a package and display the planned changes before importing:

```sh
wp rrze-migration import all website.zip --new_url=https://target.example.test/new-site/ --dry-run
wp rrze-migration import all website.zip --new_url=https://target.example.test/new-site/ --dry-run --format=json
```

Both commands use the same preflight as execution. The plan shows the source and destination, estimated site ID, table mapping, existing/new WordPress user actions, media files and destination storage. A dry-run creates only private temporary extraction files and removes them afterwards; it does not create sites, users, tables or destination uploads. Normal WordPress/plugin bootstrap still runs. JSON plans include SSO logins and local paths; treat saved plans as internal operational data.

The next site ID is an estimate, not a reservation. Execution rebuilds the plan under the migration lock and checks the allocated ID before WordPress initializes it. Existing tables, upload directories (even empty ones), links or membership metadata for that ID block the import. Nothing is automatically removed. A conflict discovered after the site row was inserted retains that incomplete row for manual inspection.

Packages use format version 1 in `site.json`, with exact filenames, sizes and SHA-256 hashes for `users.csv`, `tables.sql` and every included upload. Unsupported/unversioned packages must be exported again. Checksums detect transfer damage and changed contents; they do not authenticate the producer or make arbitrary SQL safe. Unexpected entries, path collisions, links, encrypted archives and server-executable upload files are rejected before import.

Current bounds: 2 GiB ZIP, 4 GiB expanded data, 100,000 entries, 512 MiB per media file, 64 MiB SQL, 16 MiB CSV, 4 MiB metadata and 10,000 users. Entries larger than 1 MiB must not exceed a 200:1 compression ratio. Available PHP memory, temporary storage and upload storage are checked conservatively; these checks cannot reserve capacity or determine free space on the database server.

Destination preflight requires explicit database/schema grants sufficient for the migration. Grants available only through roles or individual tables are not yet supported. Standard multisite uploads under `wp-content/uploads/sites/<ID>` are supported; custom upload paths, upload filters and legacy `ms-files.php` layouts need a migration adapter and currently block the import. Source upload path options are reset for the newly created destination.

### Import

Run from the destination WordPress directory. Use the global `--url` to select the destination network context when needed.

```sh
wp rrze-migration import all website.zip --new_url=https://target.example.test/new-site/ --uid_fields=_fixture_user --run-dir=/srv/private/rrze-migrations
```

User matching uses the exact SSO `user_login`; the company email must agree. Conflicting logins/emails, duplicate CSV identities and unknown destination roles stop the import before a new site is created. New WordPress users receive a fresh random local password; this does not provision an SSO account. Existing global user profiles, passwords and memberships on other sites are preserved. Only the new site's membership is added.

User CSV exports exclude passwords, reset keys, application passwords, sessions and global permission fields, including attempts to add these through export filters. Legacy credential fields inside a supported versioned package are discarded on import. New users receive the supported standard profile fields; arbitrary custom user metadata and former import hooks are not replayed.

Actual imports require a private persistent `--run-dir` outside web roots, or `RRZE_MIGRATION_RUN_DIR` in the destination configuration. Each run retains its validated input package and an atomic checkpoint journal without credentials. Dry-runs require no journal directory. See [Run status and recovery](docs/migration-recovery.md) (German) for setup, interruption handling and retention.

```sh
wp rrze-migration status RUN_ID --run-dir=/srv/private/rrze-migrations --format=json
```

Imports sharing the database and base prefix run one at a time, including imports to different destination URLs. Each created site is tagged with its run ID and ownership is checked between steps. Before success, the importer verifies destination tables, URLs, user mappings, existing participant profiles and packaged media. A running step without a completion checkpoint may have partially or fully executed; individual steps are never replayed automatically.

Database export/import and URL replacement run in child processes. A failed command stops the migration with a nonzero status. A partially created site is retained for inspection and must be deleted manually before a retry. The import never removes sites or global users automatically.

### Compatibility and remaining work

- `import tables`, `import users` and `posts update_author` cannot be used to mutate an existing site directly.
- `--usersuffix` is rejected because SSO identities must remain unchanged.
- `--plugins` and `--themes`, including legacy packages containing their code, are rejected. Provide dependencies separately in the destination.
- `--mysql-single-transaction` is rejected: wrapping a dump containing DDL does not make the migration atomic.

Use packages from controlled exports only. Current SQL table checks are not a sandbox for arbitrary SQL. Full SQL isolation, extension compatibility, concurrency with external writers and real SSO acceptance remain part of the following work packages. Recovery uses the retained package for a fresh import after manual site deletion; it does not restore an independently deleted old site or roll back global tables. A successful dry-run does not execute SQL and cannot guarantee a successful import. An import without packaged media does not verify a separate media transfer.
