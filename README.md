# RRZE-CLI

WP-CLI extension for RRZE's CMS management.

## Requirements

-   PHP >= 8.2
-   WP-CLI >= 2.11.0

## Migration

This WP-CLI extension simplifies the process of migrating websites on a WordPress multisite installation. It exports everything to a ZIP package, which can then be automatically imported into the desired multisite installation.

### Export

The `rrze-migration export` command exports an entire website into a ZIP package.

```
$ wp rrze-migration export all
```

You can also export websites from a Multisite instance by passing the `--url` parameter. For example:

```
$ wp rrze-migration export all --url=website-url
```
In some special cases, `rrze-migration export` may not detect all custom tables when exporting a website to a Multisite instance. If you need to migrate non-standard tables, you can use the `--tables` or `--custom-tables` parameter. For example:

```
$ wp rrze-migration export all --url=website-url --custom-tables=custom_table_1,custom_table_2
```

If you pass `--tables`, only the specified tables will be exported. Therefore, when using this option, ensure that all necessary tables, including WordPress default tables, are included in the export.

If you pass `--uploads`, the files in the media library will also be exported. However, it is only recommended to use this option where the media library does not exceed 500 MB in total. Otherwise, it is recommended to use `rsync` for example.

### Import

The `rrze-migration import` command can be used to import a website from a ZIP package.

```
$ wp rrze-migration import all website.zip
```
When importing into a Multisite instance, a new website within the Multisite network is created based on the exported website. When importing into a standalone installation, the current website is overwritten with the exported website.

The `rrze-migration import all` command handles everything required for migrating a website within a Multisite instance.

If you need to set up a new URL for the imported website, you can pass it to the `rrze-migration import all` command.

```
$ wp rrze-migration import all website.zip --new_url=new-website-url
```

The `rrze-migration import` command also supports the `--mysql-single-transaction` parameter, which wraps the SQL export into a single transaction to commit all import changes at once, preventing database server overload.

```
$ wp rrze-migration import all website.zip --new_url=new-website-url --mysql-single-transaction
```

### Notes

If themes and plugins are developed according to WordPress standards, migration should proceed without major issues. However, depending on the codebase of the website being migrated, you may need to make some adjustments to the code.