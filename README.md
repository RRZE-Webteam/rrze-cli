# RRZE-CLI

WP-CLI extension for RRZE's CMS management.

## Requirements

-   PHP >= 8.2
-   WP-CLI >= 2.11.0

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

Core tables and site-owned custom tables of a subsite are selected by default. For a main site, the default includes only its core tables: its base prefix also matches global and other-site tables. Additional main-site tables require explicit selection and an administrative check that they belong exclusively to that site.

```sh
wp rrze-migration export all website.zip --url=https://source.example.test/ --custom-tables=wp_local_data --uploads
```

`--custom-tables` adds to the default selection. `--tables` selects an explicit list, but a full ZIP package must still contain every core site table. Global user/network tables and other sites' tables cannot be selected. Separate SQL and CSV exports remain available through `export tables` and `export users`.

### Import

Run from the destination WordPress directory. Use the global `--url` to select the destination network context when needed.

```sh
wp rrze-migration import all website.zip --new_url=https://target.example.test/new-site/ --uid_fields=_fixture_user
```

User matching uses the exact SSO `user_login`; the company email must agree. Conflicting logins/emails, duplicate CSV identities and unknown destination roles stop the import before a new site is created. New WordPress users receive a fresh random local password; this does not provision an SSO account. Existing global user profiles, passwords and memberships on other sites are preserved. Only the new site's membership is added.

User CSV exports exclude passwords, reset keys, application passwords, sessions and global permission fields, including attempts to add these through export filters. Legacy credential fields are discarded on import. New users receive the supported standard profile fields; arbitrary custom user metadata and former import hooks are not replayed.

Database export/import and URL replacement run in child processes. A failed command stops the migration with a nonzero status. A partially created site is retained for inspection and must be deleted manually before a retry. The import never removes sites or global users automatically.

### Compatibility and remaining work

- `import tables`, `import users` and `posts update_author` cannot be used to mutate an existing site directly.
- `--usersuffix` is rejected because SSO identities must remain unchanged.
- `--plugins` and `--themes`, including legacy packages containing their code, are rejected. Provide dependencies separately in the destination.
- `--mysql-single-transaction` is rejected: wrapping a dump containing DDL does not make the migration atomic.

Use packages from controlled exports only. Current SQL table checks are not a sandbox for arbitrary SQL. Full package validation, resource limits, interrupted-run recovery, extension compatibility, complete concurrency coverage and real SSO acceptance remain part of the following work packages. An import without packaged media does not verify a separate media transfer.
