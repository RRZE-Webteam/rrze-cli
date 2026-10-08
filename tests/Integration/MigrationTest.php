<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RRZE\CLI\Tests\Sandbox;

final class MigrationTest extends TestCase
{
    private static Sandbox $sandbox;

    public static function setUpBeforeClass(): void
    {
        self::$sandbox = new Sandbox();
        try {
            self::$sandbox->start();
        } catch (Throwable $error) {
            self::$sandbox->close();
            throw $error;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$sandbox->close();
        self::assertDirectoryDoesNotExist(self::$sandbox->root);
    }

    public function testControlSnapshotDetectsAnUnrelatedSiteChange(): void
    {
        $before = self::$sandbox->fixture('target', 'snapshot', ['2']);
        try {
            self::$sandbox->fixture('target', 'mutate-control');
            self::assertNotSame($before, self::$sandbox->fixture('target', 'snapshot', ['2']));
        } finally {
            self::$sandbox->fixture('target', 'restore-control');
        }
        self::assertSame($before, self::$sandbox->fixture('target', 'snapshot', ['2']));
    }

    public function testDryRunReportsMappingsWithoutChangingDatabaseOrUploads(): void
    {
        self::$sandbox->exportPackage();
        $before = self::$sandbox->fixture('target', 'state');
        $files = $this->uploadsSnapshot();
        $args = ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/planned/', '--dry-run'];
        $json = self::$sandbox->wp('target', [...$args, '--format=json']);
        $plan = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('create_new_site', $plan['action']);
        self::assertFalse($plan['overwrite']);
        self::assertSame(3, $plan['destination_details']['estimated_site_id']);
        self::assertSame('dst_3_posts', $plan['tables']['src_2_posts']);
        $users = array_column($plan['users'], null, 'login');
        self::assertSame('add_site_membership', $users['sso0001']['action']);
        self::assertSame(4, $users['sso0001']['target_id']);
        self::assertSame('create_wordpress_user', $users['sso0002']['action']);
        self::assertTrue($plan['uploads']['included']);
        self::assertGreaterThan(0, $plan['uploads']['files']);
        self::assertStringNotContainsString('user_pass', $json);
        self::assertStringNotContainsString('synthetic-session-secret', $json);
        $text = self::$sandbox->wp('target', $args);
        self::assertStringContainsString('Dry-run complete', $text);
        self::assertStringContainsString('no overwrite', $text);
        self::assertSame($before, self::$sandbox->fixture('target', 'state'));
        self::assertSame($files, $this->uploadsSnapshot());
        $this->assertWorkspaceClean();
    }

    public function testExportImportPreservesContentAndProtectsExistingSiteAndUsers(): void
    {
        $sourceBefore = self::$sandbox->fixture('source', 'snapshot', ['2']);
        $controlBefore = self::$sandbox->fixture('target', 'snapshot', ['2']);
        $usersBefore = self::$sandbox->fixture('target', 'users');
        $source = self::$sandbox->fixture('source', 'content', ['2']);
        self::$sandbox->exportPackage();
        $plan = json_decode(self::$sandbox->wp('target', ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/imported/', '--uid_fields=_fixture_user', '--dry-run', '--format=json']), true, 512, JSON_THROW_ON_ERROR);
        self::$sandbox->wp('target', ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/imported/', '--uid_fields=_fixture_user']);
        $sites = self::$sandbox->fixture('target', 'sites');
        $imported = array_values(array_filter($sites, fn ($site) => $site['path'] === '/imported/'));
        self::assertCount(1, $imported);
        $id = $imported[0]['id'];
        self::assertNotSame(2, $id, 'The control site must not be reused.');
        self::assertSame($plan['destination_details']['estimated_site_id'], $id);
        self::assertSame([], array_diff(array_values($plan['tables']), array_keys(self::$sandbox->fixture('target', 'state'))));
        $content = self::$sandbox->fixture('target', 'content', [(string) $id]);
        self::assertSame($source['title'], $content['title']);
        self::assertSame(str_replace('http://source.test/source/', 'http://target.test/imported/', $source['content']), $content['content']);
        self::assertSame($source['meta']['nested'], $content['meta']['nested']);
        self::assertSame('http://target.test/imported/about/', $content['meta']['link']);
        self::assertSame('http://target.test/imported/about/', $content['option']['url']);
        self::assertSame($source['option']['values'], $content['option']['values']);
        self::assertSame($content['expected_parent'], $content['parent']);
        self::assertSame($content['post_id'], $content['attachment_parent']);
        self::assertSame($source['categories'], $content['categories']);
        self::assertSame($source['media_hash'], $content['media_hash']);
        self::assertNotNull($content['media_hash']);
        self::assertSame('Custom table Grüße', $content['custom']);
        self::assertSame(4, $content['shared'], 'Existing sso0001 must be mapped from source ID 2 to target ID 4.');
        self::assertSame(5, $content['author'], 'New sso0002 must be mapped from source ID 3 to target ID 5.');
        self::assertSame($sourceBefore, self::$sandbox->fixture('source', 'snapshot', ['2']));
        self::assertSame($controlBefore, self::$sandbox->fixture('target', 'snapshot', ['2']));
        $usersAfter = self::$sandbox->fixture('target', 'users');
        foreach ($usersBefore as $userId => $before) {
            $after = $usersAfter[$userId];
            // Only the newly created site's membership keys are permitted to change.
            unset($after['meta']['dst_' . $id . '_capabilities'], $after['meta']['dst_' . $id . '_user_level']);
            self::assertSame($before, $after, 'An existing global user changed beyond new-site membership.');
        }
    }

    public function testExistingDestinationIsRejectedWithoutChangingTheControlSite(): void
    {
        self::$sandbox->exportPackage();
        $before = self::$sandbox->fixture('target', 'snapshot', ['2']);
        $users = self::$sandbox->fixture('target', 'users');
        $sites = self::$sandbox->fixture('target', 'sites');
        $result = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/control/']);
        self::assertNotSame(0, $result['code']);
        self::assertStringNotContainsString('All done', $result['stdout']);
        self::assertSame($before, self::$sandbox->fixture('target', 'snapshot', ['2']));
        self::assertSame($users, self::$sandbox->fixture('target', 'users'));
        self::assertSame($sites, self::$sandbox->fixture('target', 'sites'));
    }

    public function testSingleSiteDestinationIsRejectedWithoutDatabaseChanges(): void
    {
        $before = self::$sandbox->fixture('single', 'state');
        $result = self::$sandbox->command('single', ['rrze-migration', 'import', 'all', 'nonexistent.zip']);
        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('multisite destination', $result['stderr']);
        self::assertSame($before, self::$sandbox->fixture('single', 'state'));
        $this->assertWorkspaceClean();
    }

    public function testEveryExistingSiteStatusAndEquivalentUrlBlocksImport(): void
    {
        foreach (['archived' => 1, 'deleted' => 1, 'spam' => 1, 'public' => 0, 'mature' => 1] as $field => $value) {
            $status = self::$sandbox->fixture('target', 'status', [$field, (string) $value]);
            try {
                // Scheme, host case and trailing slash must not create a different identity.
                $this->assertRejected('nonexistent.zip', 'https://TARGET.test/control', 'already exists');
            } finally {
                self::$sandbox->fixture('target', 'status', [$field, (string) $status['before']]);
            }
        }
    }

    public function testDirectMutationCommandsCannotTargetAnExistingSite(): void
    {
        $before = self::$sandbox->fixture('target', 'state');
        foreach ([['import', 'tables'], ['import', 'users'], ['posts', 'update_author']] as $command) {
            $result = self::$sandbox->command('target', ['rrze-migration', ...$command, 'missing.file', '--blog_id=2']);
            self::assertNotSame(0, $result['code']);
            self::assertStringNotContainsString('All done', $result['stdout']);
        }
        self::assertSame($before, self::$sandbox->fixture('target', 'state'));
    }

    public function testMainSiteExportExcludesGlobalAndOtherSiteTables(): void
    {
        self::$sandbox->wp('source', ['rrze-migration', 'export', 'tables', 'main tables.sql', '--url=http://source.test/']);
        $sql = file_get_contents(self::$sandbox->root . '/source/main tables.sql');
        self::assertStringContainsString('CREATE TABLE `src_posts`', $sql);
        self::assertStringContainsString('CREATE TABLE `src_options`', $sql);
        foreach (['src_users', 'src_usermeta', 'src_blogs', 'src_site', 'src_sitemeta', 'src_2_posts', 'src_2_rrze_fixture'] as $table) {
            self::assertStringNotContainsString('CREATE TABLE `' . $table . '`', $sql);
        }
        $result = self::$sandbox->command('source', ['rrze-migration', 'export', 'tables', 'unsafe.sql', '--tables=src_users', '--url=http://source.test/']);
        self::assertNotSame(0, $result['code']);
        self::assertFileDoesNotExist(self::$sandbox->root . '/source/unsafe.sql');
    }

    public function testCustomTablesAddToCoreTablesAndOutputIsNeverOverwritten(): void
    {
        $args = ['rrze-migration', 'export', 'tables', 'custom tables.sql', '--custom-tables=src_2_rrze_fixture', '--url=http://source.test/source/'];
        self::$sandbox->wp('source', $args);
        $file = self::$sandbox->root . '/source/custom tables.sql';
        $sql = file_get_contents($file);
        foreach (['src_2_posts', 'src_2_options', 'src_2_rrze_fixture'] as $table) {
            self::assertStringContainsString('CREATE TABLE `' . $table . '`', $sql);
        }
        $result = self::$sandbox->command('source', $args);
        self::assertNotSame(0, $result['code']);
        self::assertSame($sql, file_get_contents($file));
        $package = self::$sandbox->exportPackage();
        $hash = hash_file('sha256', $package);
        $result = self::$sandbox->command('source', ['rrze-migration', 'export', 'all', 'fixture.zip', '--url=http://source.test/source/']);
        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertCount(2, glob(self::$sandbox->root . '/runs-source/export-*/fixture.zip'));
        self::assertSame($hash, hash_file('sha256', $package));
        self::assertFileDoesNotExist(self::$sandbox->root . '/source/fixture.zip');
        $this->assertWorkspaceClean();
    }

    public function testSsoConflictsStopBeforeCreatingASiteOrChangingAnyUsers(): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/user-conflicts.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as $case) {
            $name = $this->modifiedPackage(function (ZipArchive $zip) use ($case) {
                $this->rewriteCsv($zip, static function (array $row) use ($case): array {
                    if ($row['user_login'] === 'sso0001') {
                        $row['user_login'] = $case['source']['user_login'];
                        $row['user_email'] = $case['source']['user_email'];
                    }
                    return $row;
                });
            });
            $this->assertRejected($name, 'http://target.test/conflict/', 'SSO identity conflict');
        }
    }

    public function testExportFiltersCannotReintroduceCredentialsOrRenameIdentities(): void
    {
        $hook = self::$sandbox->root . '/source/wp-content/mu-plugins/credential-filter.php';
        file_put_contents($hook, <<<'PHP'
<?php
add_filter('rrze_migration_export_user_headers', fn () => ['user_pass', '_application_passwords', 'session_tokens', 'user_activation_key', 'src_capabilities', 'harmless_field']);
add_filter('rrze_migration_export_user_data', fn () => ['user_login' => 'renamed', 'user_pass' => 'injected-secret', 'session_tokens' => 'injected-secret', 'harmless_field' => 'kept']);
PHP);
        try {
            self::$sandbox->wp('source', ['rrze-migration', 'export', 'users', 'filtered.csv', '--url=http://source.test/source/']);
            $csv = file_get_contents(self::$sandbox->root . '/source/filtered.csv');
            foreach (['user_pass', '_application_passwords', 'session_tokens', 'user_activation_key', 'src_capabilities', 'injected-secret', 'synthetic-session-secret', 'synthetic-application-secret', 'synthetic-reset-secret', 'renamed'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $csv);
            }
            self::assertStringContainsString('sso0001', $csv);
            self::assertStringContainsString('harmless_field', $csv);
            self::assertStringContainsString('kept', $csv);
        } finally {
            unlink($hook);
        }
        $result = self::$sandbox->command('source', ['rrze-migration', 'export', 'users', 'renamed.csv', '--usersuffix=@other', '--url=http://source.test/source/']);
        self::assertNotSame(0, $result['code']);
        self::assertFileDoesNotExist(self::$sandbox->root . '/source/renamed.csv');
    }

    public function testLegacyCredentialsAreIgnoredWhenCreatingANewSsoUser(): void
    {
        $name = $this->modifiedPackage(function (ZipArchive $zip) {
            $this->rewriteCsv($zip, static function (array $row): array {
                if ($row['user_login'] === 'sso0002') {
                    $row['user_login'] = 'legacysso01';
                    $row['user_email'] = 'legacysso01@company.example';
                }
                return $row + [
                    'user_pass' => password_hash('legacy-known-password', PASSWORD_BCRYPT),
                    '_application_passwords' => 'legacy-application-secret', 'session_tokens' => 'legacy-session-secret',
                    'user_activation_key' => 'legacy-reset-secret', 'dst_capabilities' => 'administrator',
                ];
            });
        });
        self::$sandbox->wp('target', ['rrze-migration', 'import', 'all', $name, '--new_url=http://target.test/legacy/']);
        self::assertSame([
            'login' => 'legacysso01', 'email' => 'legacysso01@company.example', 'legacy_password_works' => false,
            'reset_key_empty' => true, 'application_passwords_empty' => true, 'sessions_empty' => true, 'superadmin' => false,
        ], self::$sandbox->fixture('target', 'credentials', ['legacysso01']));
        $this->assertWorkspaceClean();
    }

    public function testMalformedPackagesAndGlobalSqlAreRejectedBeforeSiteCreation(): void
    {
        foreach (['csv', 'metadata', 'global-table', 'foreign-reference', 'code', 'traversal'] as $case) {
            $name = $this->modifiedPackage(static function (ZipArchive $zip) use ($case) {
                if ($case === 'csv') {
                    $zip->addFromString('users.csv', "ID,user_login,user_email,role\n1,broken\n");
                } elseif ($case === 'metadata') {
                    $zip->addFromString('site.json', '{broken');
                } elseif ($case === 'global-table') {
                    $zip->addFromString('tables.sql', $zip->getFromName('tables.sql') . "\nCREATE TABLE `src_users` (`ID` bigint);\n");
                } elseif ($case === 'foreign-reference') {
                    $zip->addFromString('tables.sql', $zip->getFromName('tables.sql') . "\nINSERT INTO `dst_users` VALUES (1);\n");
                } elseif ($case === 'code') {
                    $zip->addFromString('wp-content/plugins/unwanted/plugin.php', '<?php');
                } else {
                    $zip->addFromString('../escape.txt', 'must never be extracted');
                }
            });
            $this->assertRejected($name, 'http://target.test/invalid/');
        }
        self::assertFileDoesNotExist(self::$sandbox->root . '/escape.txt');
    }

    public function testSqlImportFailureStopsBeforeUsersOrSuccessAndLeavesNewSiteForInspection(): void
    {
        $name = $this->modifiedPackage(static function (ZipArchive $zip) {
            $zip->addFromString('tables.sql', $zip->getFromName('tables.sql') . "\nTHIS IS INTENTIONALLY INVALID SQL;\n");
        });
        $users = self::$sandbox->fixture('target', 'users');
        $control = self::$sandbox->fixture('target', 'snapshot', ['2']);
        $result = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', $name, '--new_url=http://target.test/failed-sql/']);
        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('db import', $result['stderr']);
        self::assertStringContainsString('may be incomplete', $result['stderr']);
        self::assertStringNotContainsString('All done', $result['stdout']);
        self::assertSame($users, self::$sandbox->fixture('target', 'users'));
        self::assertSame($control, self::$sandbox->fixture('target', 'snapshot', ['2']));
        $sites = array_filter(self::$sandbox->fixture('target', 'sites'), fn ($site) => $site['path'] === '/failed-sql/');
        self::assertCount(1, $sites, 'A failed new site must not be automatically deleted or reused.');
        $this->assertRejected('fixture.zip', 'http://target.test/failed-sql/', 'already exists');
    }

    public function testSearchReplaceFailureStopsBeforeImportingUsers(): void
    {
        self::$sandbox->exportPackage();
        $hook = self::$sandbox->root . '/target/wp-content/mu-plugins/fail-search.php';
        file_put_contents($hook, '<?php WP_CLI::add_hook("before_invoke:search-replace", static function () { WP_CLI::error("Injected search-replace failure"); });');
        $users = self::$sandbox->fixture('target', 'users');
        try {
            $result = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/failed-replace/']);
            self::assertNotSame(0, $result['code']);
            self::assertStringContainsString('search-replace', $result['stderr']);
            self::assertStringNotContainsString('All done', $result['stdout']);
            self::assertSame($users, self::$sandbox->fixture('target', 'users'));
            $this->assertWorkspaceClean();
        } finally {
            unlink($hook);
        }
    }

    public function testCorruptChecksumsAndUnsupportedVersionsBlockDryRunAndImport(): void
    {
        foreach (['checksum', 'version', 'unversioned'] as $case) {
            $name = $this->modifiedPackage(static function (ZipArchive $zip) use ($case) {
                if ($case === 'checksum') {
                    $zip->addFromString('users.csv', str_replace('sso0001', 'sso0099', $zip->getFromName('users.csv')));
                } else {
                    $meta = json_decode($zip->getFromName('site.json'), true);
                    $meta['format_version'] = $case === 'version' ? 999 : null;
                    $zip->addFromString('site.json', json_encode($meta));
                }
            }, false);
            $this->assertRejected($name, 'http://target.test/package-conflict/');
            $before = self::$sandbox->fixture('target', 'state');
            $result = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', $name, '--new_url=http://target.test/package-conflict/', '--dry-run', '--format=json']);
            self::assertNotSame(0, $result['code']);
            self::assertSame($before, self::$sandbox->fixture('target', 'state'));
        }
    }

    public function testOrphanTablesFilesAndMembershipsBlockBeforeSiteCreation(): void
    {
        self::$sandbox->exportPackage();
        foreach (['table', 'files', 'membership'] as $kind) {
            self::$sandbox->fixture('target', 'leftover', [$kind, 'create']);
            try {
                $files = $this->uploadsSnapshot();
                $this->assertRejected('fixture.zip', 'http://target.test/orphan/', 'Pre-existing');
                $before = self::$sandbox->fixture('target', 'state');
                $result = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/orphan/', '--dry-run']);
                self::assertNotSame(0, $result['code']);
                self::assertSame($before, self::$sandbox->fixture('target', 'state'));
                self::assertSame($files, $this->uploadsSnapshot());
            } finally {
                self::$sandbox->fixture('target', 'leftover', [$kind, 'remove']);
            }
        }
    }

    public function testUnwritableUploadsAndUnverifiableGrantsBlockBeforeSiteCreation(): void
    {
        $directory = self::$sandbox->root . '/target/wp-content/uploads/sites';
        chmod($directory, 0500);
        try {
            $this->assertRejected('fixture.zip', 'http://target.test/permissions/', 'not writable');
        } finally {
            chmod($directory, 0755);
        }
        $hook = self::$sandbox->root . '/target/wp-content/mu-plugins/limited-grants.php';
        file_put_contents($hook, <<<'PHPHOOK'
<?php
add_filter('query', static fn ($query) => $query === 'SHOW GRANTS FOR CURRENT_USER()' ? "SELECT 'GRANT SELECT ON *.* TO fixture'" : $query);
PHPHOOK);
        try {
            $this->assertRejected('fixture.zip', 'http://target.test/permissions/', 'database permissions');
        } finally {
            unlink($hook);
        }
    }

    public function testUploadsAtTheMediaRootBecomeReadableOutsideThePrivateWorkspace(): void
    {
        $name = $this->modifiedPackage(static function (ZipArchive $zip) {
            $remove = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->getNameIndex($index);
                if (str_starts_with($entry, 'wp-content/uploads/')) {
                    $remove[] = $entry;
                }
            }
            foreach ($remove as $entry) {
                $zip->deleteName($entry);
            }
            $zip->addFromString('wp-content/uploads/root-media.txt', 'public fixture media');
        });
        self::$sandbox->wp('target', ['rrze-migration', 'import', 'all', $name, '--new_url=http://target.test/root-media/']);
        $site = array_values(array_filter(self::$sandbox->fixture('target', 'sites'), fn ($site) => $site['path'] === '/root-media/'))[0];
        $file = self::$sandbox->root . '/target/wp-content/uploads/sites/' . $site['id'] . '/root-media.txt';
        self::assertSame('public fixture media', file_get_contents($file));
        self::assertSame(0644, fileperms($file) & 0777);
        $this->assertWorkspaceClean();
    }

    public function testLateTableConflictIsCheckedBeforeWordPressInitialization(): void
    {
        $hook = self::$sandbox->root . '/target/wp-content/mu-plugins/late-conflict.php';
        file_put_contents($hook, <<<'PHPHOOK'
<?php
add_action('wp_insert_site', static function ($site) {
    global $wpdb;
    $prefix = $wpdb->get_blog_prefix($site->id);
    $wpdb->query("CREATE TABLE `{$prefix}options` (marker varchar(40))");
    $wpdb->query("INSERT INTO `{$prefix}options` VALUES ('protected late orphan')");
}, PHP_INT_MIN);
PHPHOOK);
        $users = self::$sandbox->fixture('target', 'users');
        $control = self::$sandbox->fixture('target', 'snapshot', ['2']);
        try {
            $result = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/late-conflict/']);
            self::assertNotSame(0, $result['code']);
            self::assertStringContainsString('Pre-existing tables', $result['stderr']);
            self::assertStringContainsString('may be incomplete', $result['stderr']);
            self::assertSame($users, self::$sandbox->fixture('target', 'users'));
            self::assertSame($control, self::$sandbox->fixture('target', 'snapshot', ['2']));
            $site = array_values(array_filter(self::$sandbox->fixture('target', 'sites'), fn ($site) => $site['path'] === '/late-conflict/'))[0];
            $value = self::$sandbox->wp('target', ['db', 'query', 'SELECT marker FROM dst_' . $site['id'] . '_options', '--skip-column-names']);
            self::assertStringContainsString('protected late orphan', $value);
            $this->assertWorkspaceClean();
        } finally {
            unlink($hook);
        }
    }

    public function testSuccessfulRunHasVerifiedJournalAndRetainedPackage(): void
    {
        $runs = glob(self::$sandbox->root . '/runs-target/*/run.json');
        $completed = [];
        foreach ($runs as $file) {
            $state = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            if ($state['status'] === 'completed') {
                $completed[] = $state;
            }
        }
        self::assertNotEmpty($completed);
        $state = $this->runStatus($completed[0]['run_id']);
        self::assertSame('completed', $state['observed_status']);
        self::assertSame('completed', $state['steps']['verify']['status']);
        self::assertSame('completed', $state['steps']['cleanup']['status']);
        self::assertTrue($state['package_intact']);
        self::assertFalse($state['active']);
        self::assertTrue($state['uploads']['verified']);
        self::assertFalse($state['uploads']['skipped']);
        self::assertSame(0600, fileperms($state['preserved_package']) & 0777);
    }

    public function testEveryWritingPhaseRecordsFailureAndDoesNotReportSuccess(): void
    {
        $hook = self::$sandbox->root . '/target/wp-content/mu-plugins/fail-phase.php';
        $control = self::$sandbox->fixture('target', 'snapshot', ['2']);
        foreach (['import_tables', 'replace_urls', 'configure_site', 'import_users', 'remap_references', 'import_uploads', 'finalize', 'verify'] as $phase) {
            file_put_contents($hook, '<?php add_action("rrze_migration_after_step", static function ($step) { if ($step === ' . var_export($phase, true) . ') { throw new RuntimeException("synthetic-secret-not-for-journal"); } });');
            try {
                $result = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/fault-' . $phase . '/', '--uid_fields=_fixture_user']);
                self::assertNotSame(0, $result['code']);
                self::assertStringNotContainsString('All done', $result['stdout']);
                $state = $this->runStatus($this->runId($result));
                self::assertSame('failed', $state['status']);
                self::assertSame($phase, $state['failure_step']);
                self::assertSame('started', $state['steps'][$phase]['status']);
                self::assertSame('completed', $state['steps']['cleanup']['status']);
                self::assertNotNull($state['site_id']);
                self::assertTrue($state['package_intact']);
                $journal = file_get_contents(self::$sandbox->root . '/runs-target/' . $state['run_id'] . '/run.json');
                foreach (['synthetic-secret-not-for-journal', 'user_pass', 'user_email', 'session_tokens', '_application_passwords', 'synthetic-session-secret', 'synthetic-application-secret'] as $secret) {
                    self::assertStringNotContainsString($secret, $journal);
                }
                $this->assertWorkspaceClean();
            } finally {
                unlink($hook);
            }
        }
        self::assertSame($control, self::$sandbox->fixture('target', 'snapshot', ['2']));
    }

    public function testRecoveryAfterManualDeletionReusesCreatedGlobalUserAndPreservedPackage(): void
    {
        $name = $this->modifiedPackage(function (ZipArchive $zip) {
            $this->rewriteCsv($zip, static function ($row) {
                if ($row['user_login'] === 'sso0002') {
                    $row['user_login'] = 'recovery01';
                    $row['user_email'] = 'recovery01@company.example';
                }
                return $row;
            });
        });
        $hook = self::$sandbox->root . '/target/wp-content/mu-plugins/recovery-failure.php';
        file_put_contents($hook, '<?php add_action("rrze_migration_after_step", static function ($step) { if ($step === "import_users") { throw new RuntimeException("Recovery fixture interruption"); } });');
        $control = self::$sandbox->fixture('target', 'snapshot', ['2']);
        try {
            $failed = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', $name, '--new_url=http://target.test/recovered/', '--uid_fields=_fixture_user']);
            self::assertNotSame(0, $failed['code']);
        } finally {
            unlink($hook);
        }
        $state = $this->runStatus($this->runId($failed));
        self::assertTrue($state['users'][3]['created']);
        $user = self::$sandbox->fixture('target', 'lookup-user', ['recovery01']);
        self::assertSame($state['users'][3]['target_id'], $user['id']);
        $this->assertRejected($state['preserved_package'], 'http://target.test/recovered/', 'already exists');
        self::assertSame(['deleted' => true], self::$sandbox->fixture('target', 'delete-migration-site', [(string) $state['site_id']]));
        self::assertSame($user, self::$sandbox->fixture('target', 'lookup-user', ['recovery01']));
        unlink(self::$sandbox->root . '/runs-target/' . $name);
        $plan = json_decode(self::$sandbox->wp('target', ['rrze-migration', 'import', 'all', $state['preserved_package'], '--new_url=http://target.test/recovered/', '--uid_fields=_fixture_user', '--dry-run', '--format=json']), true, 512, JSON_THROW_ON_ERROR);
        $plannedUser = array_values(array_filter($plan['users'], static fn ($row) => $row['login'] === 'recovery01'))[0];
        self::assertSame('add_site_membership', $plannedUser['action']);
        $restored = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', $state['preserved_package'], '--new_url=http://target.test/recovered/', '--uid_fields=_fixture_user']);
        self::assertSame(0, $restored['code'], $restored['stderr']);
        $restoredState = $this->runStatus($this->runId($restored));
        self::assertNotSame($state['site_id'], $restoredState['site_id']);
        self::assertFalse($restoredState['users'][3]['created']);
        self::assertSame($user, self::$sandbox->fixture('target', 'lookup-user', ['recovery01']));
        $content = self::$sandbox->fixture('target', 'content', [(string) $restoredState['site_id']]);
        self::assertSame($user['id'], $content['author']);
        self::assertSame('Migration fixture', $content['title']);
        self::assertNotNull($content['media_hash']);
        self::assertSame($control, self::$sandbox->fixture('target', 'snapshot', ['2']));
        self::assertTrue($this->runStatus($state['run_id'])['package_intact']);
    }

    public function testInstallationLockBlocksConcurrentImportsToDifferentUrls(): void
    {
        [$hook, $ready, $release] = $this->pauseHook('create_site');
        $running = self::$sandbox->background('target', ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/concurrent-first/']);
        try {
            $this->awaitMarker($ready);
            $id = trim(file_get_contents($ready));
            self::assertTrue($this->runStatus($id)['active']);
            $before = self::$sandbox->fixture('target', 'state');
            $blocked = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/concurrent-second/']);
            self::assertNotSame(0, $blocked['code']);
            self::assertStringContainsString('Another migration is running', $blocked['stderr']);
            self::assertSame($before, self::$sandbox->fixture('target', 'state'));
        } finally {
            file_put_contents($release, 'continue');
            $result = \RRZE\CLI\Tests\Process::finish($running);
            unlink($hook);
            @unlink($ready);
            @unlink($release);
        }
        self::assertSame(0, $result['code'], $result['stderr']);
        $this->assertWorkspaceClean();
    }

    public function testHardKilledProcessLeavesAnHonestIncompleteCheckpoint(): void
    {
        [$hook, $ready, $release] = $this->pauseHook('import_users');
        $running = self::$sandbox->background('target', ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/hard-kill/']);
        try {
            $this->awaitMarker($ready);
            $id = trim(file_get_contents($ready));
            self::assertTrue($this->runStatus($id)['active']);
            proc_terminate($running['process'], 9);
        } finally {
            \RRZE\CLI\Tests\Process::finish($running);
            unlink($hook);
            @unlink($ready);
        }
        $before = self::$sandbox->fixture('target', 'state');
        $state = $this->runStatus($id);
        self::assertSame('interrupted_or_unfinished', $state['observed_status']);
        self::assertSame('started', $state['steps']['import_users']['status']);
        self::assertSame('completed', $state['steps']['configure_site']['status']);
        self::assertSame($before, self::$sandbox->fixture('target', 'state'));
        $this->assertRejectedAfterKill($state);
        $this->cleanupInterruptedWorkspace($state);
    }

    public function testCancellationStopsAtStepBoundaryAndRecordsInterruption(): void
    {
        $hook = self::$sandbox->root . '/target/wp-content/mu-plugins/cancel-run.php';
        file_put_contents($hook, '<?php add_action("rrze_migration_after_step", static function ($step) { if ($step === "import_users") { posix_kill(getmypid(), SIGTERM); } });');
        try {
            $result = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/cancelled/']);
            self::assertNotSame(0, $result['code']);
            $state = $this->runStatus($this->runId($result));
            self::assertSame('interrupted', $state['status']);
            self::assertSame('import_users', $state['failure_step']);
            self::assertArrayNotHasKey('remap_references', $state['steps']);
            self::assertSame('completed', $state['steps']['cleanup']['status']);
            $this->assertWorkspaceClean();
        } finally {
            unlink($hook);
        }
    }

    public function testFinalVerificationRejectsChangedProfilesAndDamagedMedia(): void
    {
        $hook = self::$sandbox->root . '/target/wp-content/mu-plugins/fail-verification.php';
        $profile = trim(self::$sandbox->wp('target', ['user', 'meta', 'get', '4', 'first_name']));
        $control = self::$sandbox->fixture('target', 'snapshot', ['2']);
        foreach (['profile', 'media'] as $kind) {
            $mutation = $kind === 'profile'
                ? 'update_user_meta(4, "first_name", "unexpected fixture profile change");'
                : '$state = json_decode(file_get_contents(RRZE_MIGRATION_RUN_DIR . "/" . $id . "/run.json"), true); $files = glob($state["uploads_directory"] . "/*/*/fixture.png"); file_put_contents($files[0], "damaged fixture media");';
            file_put_contents($hook, '<?php add_action("rrze_migration_before_step", static function ($step, $id) { if ($step === "verify") { ' . $mutation . ' } }, 10, 2);');
            try {
                $result = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/verify-' . $kind . '/']);
                self::assertNotSame(0, $result['code']);
                self::assertStringContainsString($kind === 'profile' ? 'existing global user changed' : 'media verification failed', $result['stderr']);
                $state = $this->runStatus($this->runId($result));
                self::assertSame('failed', $state['status']);
                self::assertSame('verify', $state['failure_step']);
                self::assertSame('started', $state['steps']['verify']['status']);
                self::assertStringNotContainsString('All done', $result['stdout']);
            } finally {
                unlink($hook);
                if ($kind === 'profile') {
                    self::$sandbox->wp('target', ['user', 'meta', 'update', '4', 'first_name', $profile]);
                }
            }
        }
        self::assertSame($control, self::$sandbox->fixture('target', 'snapshot', ['2']));
        $this->assertWorkspaceClean();
    }

    public function testLostDatabaseLockStopsBeforeTheNextWrite(): void
    {
        $hook = self::$sandbox->root . '/target/wp-content/mu-plugins/lose-lock.php';
        file_put_contents($hook, '<?php add_action("rrze_migration_before_step", static function ($step) { if ($step === "import_tables") { global $wpdb; $wpdb->get_var("SELECT RELEASE_ALL_LOCKS()"); } });');
        try {
            $result = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/lost-lock/']);
            self::assertNotSame(0, $result['code']);
            self::assertStringContainsString('database lock was lost', $result['stderr']);
            $state = $this->runStatus($this->runId($result));
            self::assertSame('failed', $state['status']);
            self::assertSame('import_tables', $state['failure_step']);
            self::assertArrayNotHasKey('replace_urls', $state['steps']);
            $this->assertWorkspaceClean();
        } finally {
            unlink($hook);
        }
    }

    public function testNativeExitPersistsInterruptionWithoutClaimingCleanup(): void
    {
        $hook = self::$sandbox->root . '/target/wp-content/mu-plugins/native-exit.php';
        file_put_contents($hook, '<?php add_action("rrze_migration_before_step", static function ($step) { if ($step === "remap_references") { exit(19); } });');
        try {
            $result = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/native-exit/']);
            self::assertSame(19, $result['code']);
            $state = $this->runStatus($this->runId($result));
            self::assertSame('interrupted', $state['observed_status']);
            self::assertSame('started', $state['steps']['remap_references']['status']);
            self::assertArrayNotHasKey('cleanup', $state['steps']);
            self::assertTrue($state['package_intact']);
            $this->cleanupInterruptedWorkspace($state);
        } finally {
            unlink($hook);
        }
    }

    public function testWizardRejectsPipesAndAutomaticApprovalWithoutDestinationChanges(): void
    {
        $before = self::$sandbox->fixture('target', 'state');
        $result = self::$sandbox->command('target', ['rrze-migration', 'wizard', 'import']);
        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('interactive input and output terminal', $result['stderr']);
        foreach (['--yes', '--quiet'] as $flag) {
            $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import', $flag], []);
            self::assertNotSame(0, $result['code']);
            self::assertStringNotContainsString('ZIP package (relative', $result['stdout']);
        }
        self::assertSame($before, self::$sandbox->fixture('target', 'state'));
    }

    public function testWizardPreviewIsDefaultAndRetriesInvalidInputWithoutWriting(): void
    {
        self::$sandbox->exportPackage();
        $before = self::$sandbox->fixture('target', 'state');
        $files = $this->uploadsSnapshot();
        $runs = glob(self::$sandbox->root . '/runs-target/*');
        $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard'], [
            ['Operation (', ''],
            ['Private migration directory', ''],
            ['ZIP package (relative', 'missing.zip'],
            ['ZIP package (relative', 'fixture.zip'],
            ['New destination URL', 'not-a-url'],
            ['New destination URL', 'http://target.test/control/'],
            ['New destination URL', 'http://target.test/wizard-preview/'],
            ['Post meta keys', '_fixture_user'],
            ['Next action', ''],
        ]);
        self::assertSame(0, $result['code'], $result['stdout']);
        self::assertSame(9, $result['answers']);
        self::assertStringContainsString('destination site already exists', $result['stdout']);
        self::assertStringContainsString('Dry-run complete', $result['stdout']);
        self::assertStringContainsString('Numeric user-reference fields: _fixture_user', $result['stdout']);
        self::assertSame($before, self::$sandbox->fixture('target', 'state'));
        self::assertSame($files, $this->uploadsSnapshot());
        self::assertSame($runs, glob(self::$sandbox->root . '/runs-target/*'));
        $this->assertWorkspaceClean();
    }

    public function testExportBySiteIdUsesTheSelectedTablesUsersUploadsAndPluginContext(): void
    {
        $url = trim(self::$sandbox->wp('source', ['option', 'get', 'home', '--url=http://source.test/source/']));
        $hook = self::$sandbox->root . '/source/wp-content/mu-plugins/source-context.php';
        file_put_contents($hook, <<<'PHPHOOK'
<?php
// Registered only when WordPress boots the requested site, not on switch_to_blog().
if (get_current_blog_id() === 2) {
    add_filter('rrze_migration_export_user_headers', static fn ($headers) => [...$headers, 'fixture_boot_site']);
    add_filter('rrze_migration_export_user_data', static fn ($data) => $data + ['fixture_boot_site' => 'booted-site-2']);
}
PHPHOOK);
        $before = self::$sandbox->fixture('source', 'state');
        try {
            foreach (['direct', 'wizard-option', 'wizard-question'] as $mode) {
                $filename = 'id-' . $mode . '.zip';
                if ($mode === 'direct') {
                    $result = self::$sandbox->command('source', ['rrze-migration', 'export', 'all', $filename, '--site-id=2', '--uploads']);
                } else {
                    $arguments = ['rrze-migration', 'wizard', 'export'];
                    $dialogue = [];
                    if ($mode === 'wizard-option') {
                        $arguments[] = '--site-id=2';
                    } else {
                        $dialogue = [['Source website ID', '2abc'], ['Source website ID', '999999999'], ['Source website ID', '2']];
                    }
                    $dialogue = [...$dialogue,
                        ['Export this website', 'yes'], ['Private migration directory', ''],
                        ['New ZIP filename', $filename], ['Additional site-owned tables', ''], ['Include uploads', 'yes'],
                        ['Upload subdirectories to exclude', ''], ['Type that complete URL', $url], ['Create this export package now', 'yes'],
                    ];
                    $result = self::$sandbox->terminal('source', $arguments, $dialogue);
                    self::assertSame(count($dialogue), $result['answers']);
                    self::assertStringContainsString('Export source: ID 2 | URL: ' . $url, $result['stdout']);
                    self::assertSame(1, substr_count($result['stdout'], 'Export this website (yes/no) [no]'));
                }
                self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
                $packages = glob(self::$sandbox->root . '/runs-source/export-*-site-2-*/' . $filename);
                self::assertCount(1, $packages);
                $zip = new ZipArchive();
                self::assertTrue($zip->open($packages[0]));
                $meta = json_decode($zip->getFromName('site.json'), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame(2, $meta['blog_id']);
                self::assertSame($url, $meta['url']);
                self::assertSame('src_2_', $meta['db_prefix']);
                self::assertContains('src_2_rrze_fixture', $meta['tables']);
                self::assertNotContains('src_posts', $meta['tables']);
                self::assertNotContains('src_users', $meta['tables']);
                $sql = $zip->getFromName('tables.sql');
                self::assertStringContainsString('CREATE TABLE `src_2_posts`', $sql);
                self::assertStringNotContainsString('CREATE TABLE `src_posts`', $sql);
                $csv = $zip->getFromName('users.csv');
                self::assertStringContainsString('sso0001', $csv);
                self::assertStringContainsString('sso0002', $csv);
                self::assertStringContainsString('booted-site-2', $csv);
                self::assertStringNotContainsString('synthetic-test-password', $csv);
                $images = array_values(array_filter(array_keys($meta['files']), static fn ($name) => str_ends_with($name, '/fixture.png')));
                self::assertCount(1, $images);
                self::assertNotFalse($zip->getFromName($images[0]));
                $zip->close();
                self::assertSame($before, self::$sandbox->fixture('source', 'state'));
                $this->assertWorkspaceClean();
            }
            // An explicit ID also overrides an initial --url pointing at a different site.
            $result = self::$sandbox->command('source', ['rrze-migration', 'export', 'all', 'id-main.zip', '--site-id=1', '--url=http://source.test/source/']);
            self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
            $packages = glob(self::$sandbox->root . '/runs-source/export-*-site-1-*/id-main.zip');
            self::assertCount(1, $packages);
            $zip = new ZipArchive();
            self::assertTrue($zip->open($packages[0]));
            $meta = json_decode($zip->getFromName('site.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $meta['blog_id']);
            self::assertSame('src_', $meta['db_prefix']);
            self::assertNotContains('src_2_posts', $meta['tables']);
            self::assertStringNotContainsString('booted-site-2', $zip->getFromName('users.csv'));
            $zip->close();
            self::assertSame($before, self::$sandbox->fixture('source', 'state'));
        } finally {
            unlink($hook);
        }
    }

    public function testExportSourceConfirmationCancellationCreatesNoOutput(): void
    {
        $before = self::$sandbox->fixture('source', 'state');
        $exports = glob(self::$sandbox->root . '/runs-source/*');
        foreach (['', 'no', '!quit', "\x04"] as $answer) {
            $result = self::$sandbox->terminal('source', ['rrze-migration', 'wizard', 'export', '--site-id=2'], [
                ['Export this website', $answer],
            ]);
            self::assertNotSame(0, $result['code'], $result['stdout']);
            self::assertSame(1, $result['answers']);
            self::assertStringContainsString('Export source: ID 2 | URL: http://source.test/source', $result['stdout']);
            self::assertStringNotContainsString('Private migration directory', $result['stdout']);
            self::assertSame($exports, glob(self::$sandbox->root . '/runs-source/*'));
            self::assertSame($before, self::$sandbox->fixture('source', 'state'));
        }
        $this->assertWorkspaceClean();
    }

    public function testInvalidExportSiteIdsAndImportSiteSelectionAreRejectedWithoutOutput(): void
    {
        $before = self::$sandbox->fixture('source', 'state');
        $exports = glob(self::$sandbox->root . '/runs-source/*');
        foreach (['0', '-2', '2abc', '2.5', '999999999'] as $id) {
            $result = self::$sandbox->command('source', ['rrze-migration', 'export', 'all', 'invalid-id.zip', '--site-id=' . $id]);
            self::assertNotSame(0, $result['code']);
            self::assertStringContainsString($id === '999999999' ? 'No website exists' : 'positive numeric website ID', $result['stderr']);
            $result = self::$sandbox->terminal('source', ['rrze-migration', 'wizard', 'export', '--site-id=' . $id], []);
            self::assertNotSame(0, $result['code']);
            self::assertStringNotContainsString('Export this website', $result['stdout']);
        }
        $result = self::$sandbox->command('single', ['rrze-migration', 'export', 'all', 'single-id.zip', '--site-id=1']);
        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('requires Multisite', $result['stderr']);
        $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import', '--site-id=2'], []);
        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('not supported for import', $result['stdout']);
        self::assertStringNotContainsString('Private migration directory', $result['stdout']);
        $result = self::$sandbox->command('source', ['rrze-migration', 'export', 'all', 'invalid-tables.zip', '--site-id=2', '--tables=src_users']);
        self::assertNotSame(0, $result['code']);
        self::assertSame($exports, glob(self::$sandbox->root . '/runs-source/*'));
        self::assertSame($before, self::$sandbox->fixture('source', 'state'));
        $this->assertWorkspaceClean();
    }

    public function testIncorrectBootstrapSiteCannotFallBackToExportingAnotherWebsite(): void
    {
        $before = self::$sandbox->fixture('source', 'state');
        $exports = glob(self::$sandbox->root . '/runs-source/*');
        $hook = self::$sandbox->root . '/source/wp-content/mu-plugins/wrong-context.php';
        file_put_contents($hook, '<?php add_action("init", static function () { if (get_current_blog_id() === 2) { switch_to_blog(1); } });');
        try {
            $result = self::$sandbox->command('source', ['rrze-migration', 'export', 'all', 'wrong-context.zip', '--site-id=2']);
            self::assertNotSame(0, $result['code']);
            self::assertStringContainsString('did not load the requested website ID', $result['stderr']);
            $result = self::$sandbox->terminal('source', ['rrze-migration', 'wizard', 'export', '--site-id=2'], []);
            self::assertNotSame(0, $result['code']);
            self::assertStringContainsString('did not load the requested website ID', $result['stdout']);
            self::assertStringNotContainsString('Export this website', $result['stdout']);
            self::assertSame($exports, glob(self::$sandbox->root . '/runs-source/*'));
            self::assertSame($before, self::$sandbox->fixture('source', 'state'));
            $this->assertWorkspaceClean();
        } finally {
            unlink($hook);
        }
    }

    public function testWizardExportsSelectedSourceWithUploadsAndProtectsExistingOutput(): void
    {
        $source = 'http://source.test/source/';
        $url = trim(self::$sandbox->wp('source', ['option', 'get', 'home', '--url=' . $source]));
        $before = self::$sandbox->fixture('source', 'snapshot', ['2']);
        $name = 'wizard export.zip';
        $dialogue = [
            ['Source website ID', ''], ['Export this website', 'yes'],
            ['Private migration directory', ''],
            ['New ZIP filename', $name], ['Additional site-owned tables', ''], ['Include uploads', ''],
            ['Upload subdirectories to exclude', ''],
            ['Type that complete URL', $url], ['Create this export package now', 'yes'],
        ];
        $result = self::$sandbox->terminal('source', ['rrze-migration', 'wizard', 'export', '--url=' . $source], $dialogue);
        self::assertSame(0, $result['code'], $result['stdout']);
        self::assertSame(9, $result['answers']);
        $packages = glob(self::$sandbox->root . '/runs-source/export-*/' . $name);
        self::assertCount(1, $packages);
        $file = $packages[0];
        self::assertFileExists($file);
        self::assertSame(0700, fileperms(dirname($file)) & 0777);
        self::assertSame(0600, fileperms($file) & 0777);
        self::assertFileDoesNotExist(self::$sandbox->root . '/source/' . $name);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file));
        $meta = json_decode($zip->getFromName('site.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $meta['blog_id']);
        self::assertTrue($meta['uploads_included']);
        self::assertCount(1, array_filter(array_keys($meta['files']), static fn ($name) => str_ends_with($name, '/fixture.png')));
        $zip->close();
        $hash = hash_file('sha256', $file);
        $result = self::$sandbox->terminal('source', ['rrze-migration', 'wizard', 'export', '--url=' . $source], $dialogue);
        self::assertSame(0, $result['code'], $result['stdout']);
        self::assertCount(2, glob(self::$sandbox->root . '/runs-source/export-*/' . $name));
        self::assertSame($hash, hash_file('sha256', $file));
        self::assertSame($before, self::$sandbox->fixture('source', 'snapshot', ['2']));
        $this->assertWorkspaceClean();
    }

    public function testWizardPackageNumberRetriesInvalidChoicesAndPreviewsTheDisplayedPath(): void
    {
        $package = self::$sandbox->exportPackage();
        $root = self::$sandbox->root . '/zip-selection';
        mkdir($root . '/export-one', 0700, true);
        mkdir($root . '/incoming', 0700);
        foreach (['export-one', 'incoming'] as $index => $directory) {
            copy($package, $root . '/' . $directory . '/site.zip');
            touch($root . '/' . $directory . '/site.zip', 1700000000 + $index);
        }
        $before = self::$sandbox->fixture('target', 'state');
        $uploads = $this->uploadsSnapshot();
        $runs = glob(self::$sandbox->root . '/runs-target/*');
        $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import'], [
            ['Private migration directory', $root],
            ['ZIP package (relative', ''],
            ['ZIP package (relative', '0'],
            ['ZIP package (relative', '999999999999999999999999'],
            ['ZIP package (relative', '2'],
            ['New destination URL', 'http://target.test/number-preview/'],
            ['Post meta keys', '_fixture_user'],
            ['Next action', ''],
        ]);
        self::assertSame(0, $result['code'], $result['stdout']);
        self::assertSame(8, $result['answers']);
        self::assertStringContainsString('[1] incoming/site.zip', $result['stdout']);
        self::assertStringContainsString('[2] export-one/site.zip', $result['stdout']);
        self::assertStringContainsString('No package is selected by default', $result['stdout']);
        self::assertStringContainsString('Choose a displayed package number', $result['stdout']);
        self::assertStringContainsString('Selected ZIP: ' . $root . '/export-one/site.zip', $result['stdout']);
        self::assertStringContainsString('Dry-run complete', $result['stdout']);
        self::assertSame($before, self::$sandbox->fixture('target', 'state'));
        self::assertSame($uploads, $this->uploadsSnapshot());
        self::assertSame($runs, glob(self::$sandbox->root . '/runs-target/*'));
        self::assertSame(['export-one', 'incoming'], array_values(array_diff(scandir($root), ['.', '..'])));
        self::assertSame(hash_file('sha256', $package), hash_file('sha256', $root . '/export-one/site.zip'));
        $this->assertWorkspaceClean();
    }

    public function testWizardEmptyListAllowsAnAbsolutePrivatePackageWithoutCreatingStorage(): void
    {
        $package = self::$sandbox->exportPackage();
        $before = self::$sandbox->fixture('target', 'state');
        $root = self::$sandbox->root . '/empty-selection';
        mkdir($root, 0700);
        foreach ([$root, $root . '/missing'] as $storage) {
            $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import'], [
                ['Private migration directory', $storage], ['ZIP package (relative', $package],
                ['New destination URL', 'http://target.test/empty-preview/'],
                ['Post meta keys', ''], ['Next action', ''],
            ]);
            self::assertSame(0, $result['code'], $result['stdout']);
            self::assertSame(5, $result['answers']);
            self::assertStringContainsString('No ZIP packages found', $result['stdout']);
            self::assertStringContainsString('Selected ZIP: ' . $package, $result['stdout']);
            self::assertStringContainsString('Dry-run complete', $result['stdout']);
        }
        self::assertSame([], glob($root . '/*'));
        self::assertSame($before, self::$sandbox->fixture('target', 'state'));
        $this->assertWorkspaceClean();
    }

    public function testWizardPackageSelectionRechecksPathsAndCancellationCreatesNoRun(): void
    {
        $package = self::$sandbox->exportPackage();
        $root = self::$sandbox->root . '/selection-recheck';
        mkdir($root, 0700);
        $before = self::$sandbox->fixture('target', 'state');
        $runs = glob(self::$sandbox->root . '/runs-target/*');
        foreach (['deleted', 'symlink', 'quit', 'eof', 'corrupt'] as $mode) {
            $file = $root . '/site.zip';
            copy($package, $file);
            $dialogue = [
                ['Private migration directory', $root],
                ['ZIP package (relative', static function () use ($mode, $file) {
                    if ($mode === 'quit' || $mode === 'eof') {
                        return $mode === 'quit' ? '!quit' : "\x04";
                    }
                    unlink($file);
                    if ($mode === 'symlink') {
                        symlink(self::$sandbox->root . '/target/wp-config.php', $file);
                    } elseif ($mode === 'corrupt') {
                        file_put_contents($file, 'invalid ZIP contents');
                    }
                    return '1';
                }],
            ];
            if (in_array($mode, ['deleted', 'symlink'], true)) {
                $dialogue[] = ['ZIP package (relative', '!quit'];
            } elseif ($mode === 'corrupt') {
                $dialogue[] = ['New destination URL', 'http://target.test/selected-corrupt/'];
                $dialogue[] = ['Post meta keys', ''];
                $dialogue[] = ['Next action', ''];
            }
            try {
                $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import'], $dialogue);
                self::assertNotSame(0, $result['code'], $result['stdout']);
                self::assertSame(count($dialogue), $result['answers']);
                self::assertStringContainsString('[1] site.zip', $result['stdout']);
                self::assertStringNotContainsString('Dry-run complete', $result['stdout']);
                self::assertStringNotContainsString('Type that complete URL', $result['stdout']);
                if (in_array($mode, ['deleted', 'symlink'], true)) {
                    self::assertStringContainsString('readable local ZIP', $result['stdout']);
                }
            } finally {
                if (file_exists($file) || is_link($file)) {
                    unlink($file);
                }
            }
        }
        self::assertSame([], glob($root . '/*'));
        self::assertSame($before, self::$sandbox->fixture('target', 'state'));
        self::assertSame($runs, glob(self::$sandbox->root . '/runs-target/*'));
        $this->assertWorkspaceClean();
    }

    public function testWizardExportCancellationCreatesNoOutput(): void
    {
        $before = self::$sandbox->fixture('source', 'state');
        foreach (['', '!quit', "\x04"] as $answer) {
            $file = 'cancel-' . bin2hex(random_bytes(3)) . '.zip';
            $exports = glob(self::$sandbox->root . '/runs-source/export-*');
            $result = self::$sandbox->terminal('source', ['rrze-migration', 'wizard', 'export', '--url=http://source.test/source/'], [
                ['Source website ID', ''], ['Export this website', 'yes'],
                ['Private migration directory', ''],
                ['New ZIP filename', $file], ['Additional site-owned tables', ''], ['Include uploads', ''],
                ['Upload subdirectories to exclude', ''],
                ['Type that complete URL', $answer],
            ]);
            self::assertNotSame(0, $result['code'], $result['stdout']);
            self::assertSame($exports, glob(self::$sandbox->root . '/runs-source/export-*'));
        }
        self::assertSame($before, self::$sandbox->fixture('source', 'state'));
        $this->assertWorkspaceClean();
    }

    public function testWizardImportCancellationCleansWorkspaceAndPreservesDestination(): void
    {
        self::$sandbox->exportPackage();
        $before = self::$sandbox->fixture('target', 'state');
        foreach (['wrong-url', 'default-no', 'quit', 'eof', 'signal', 'interrupt'] as $mode) {
            $url = 'http://target.test/wizard-cancel-' . $mode . '/';
            $dialogue = $this->wizardImportDialogue('fixture.zip', $url);
            $dialogue[] = ['Type that complete URL', $mode === 'wrong-url' ? 'http://wrong.test/' : $url];
            if ($mode !== 'wrong-url') {
                $answer = match ($mode) {
                    'quit' => '!quit', 'eof' => "\x04",
                    'signal', 'interrupt' => static function ($process) use ($mode) { proc_terminate($process, $mode === 'signal' ? SIGTERM : SIGINT); return null; },
                    default => '',
                };
                $dialogue[] = ['Create the new website and execute this plan now', $answer];
            }
            $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import'], $dialogue);
            self::assertNotSame(0, $result['code'], $result['stdout']);
            self::assertStringNotContainsString('All done', $result['stdout']);
            $state = $this->runStatus($this->runId($result));
            self::assertSame('failed', $state['observed_status']);
            self::assertNull($state['site_id']);
            self::assertArrayNotHasKey('create_site', $state['steps']);
            self::assertSame('completed', $state['steps']['cleanup']['status']);
            self::assertTrue($state['package_intact']);
            self::assertSame($before, self::$sandbox->fixture('target', 'state'));
            $this->assertWorkspaceClean();
        }
    }

    public function testWizardImportUsesReviewedPackageDespiteOriginalFileChanging(): void
    {
        $package = self::$sandbox->exportPackage();
        $root = self::$sandbox->root . '/selected-import';
        mkdir($root, 0700);
        copy($package, $root . '/fixture.zip');
        $control = self::$sandbox->fixture('target', 'snapshot', ['2']);
        $url = 'http://target.test/wizard-imported/';
        $dialogue = $this->wizardImportDialogue('1', $url);
        $dialogue[0][1] = $root;
        $dialogue[] = ['Type that complete URL', $url];
        $dialogue[] = ['Create the new website and execute this plan now', static function () use ($root) {
            file_put_contents($root . '/fixture.zip', 'changed after review');
            return 'yes';
        }];
        try {
            $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import'], $dialogue);
            self::assertSame(0, $result['code'], $result['stdout']);
            self::assertStringContainsString('[1] fixture.zip', $result['stdout']);
            self::assertStringContainsString('Selected ZIP: ' . $root . '/fixture.zip', $result['stdout']);
            $state = $this->runStatus($this->runId($result), $root);
            self::assertSame('completed', $state['observed_status']);
            self::assertTrue($state['package_intact']);
            $content = self::$sandbox->fixture('target', 'content', [(string) $state['site_id']]);
            $source = self::$sandbox->fixture('source', 'content', ['2']);
            self::assertSame($source['title'], $content['title']);
            self::assertSame($source['media_hash'], $content['media_hash']);
            self::assertSame(4, $content['shared']);
            self::assertSame($control, self::$sandbox->fixture('target', 'snapshot', ['2']));
            $this->assertWorkspaceClean();
        } finally {
            self::$sandbox->exportPackage();
        }
    }

    public function testWizardRejectsChangedUserPlanAfterConfirmation(): void
    {
        self::$sandbox->exportPackage();
        $package = $this->modifiedPackage(fn ($zip) => $this->rewriteCsv($zip, static function ($row) {
            if ($row['user_login'] === 'sso0002') {
                $row['user_login'] = 'wizardlate01';
                $row['user_email'] = 'wizardlate01@company.example';
            }
            return $row;
        }));
        $url = 'http://target.test/wizard-changed/';
        $afterExternal = null;
        $dialogue = $this->wizardImportDialogue($package, $url);
        $dialogue[] = ['Type that complete URL', $url];
        $dialogue[] = ['Create the new website and execute this plan now', static function () use (&$afterExternal) {
            self::$sandbox->wp('target', ['user', 'create', 'wizardlate01', 'wizardlate01@company.example', '--user_pass=synthetic-test-password', '--role=subscriber']);
            $afterExternal = self::$sandbox->fixture('target', 'state');
            return 'yes';
        }];
        $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import'], $dialogue);
        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('plan changed during review', $result['stdout']);
        self::assertNotNull($afterExternal);
        self::assertSame($afterExternal, self::$sandbox->fixture('target', 'state'));
        self::assertNull($this->runStatus($this->runId($result))['site_id']);
        $this->assertWorkspaceClean();
    }

    public function testWizardRejectsDestinationClaimedWhileReviewing(): void
    {
        self::$sandbox->exportPackage();
        $url = 'http://target.test/wizard-claimed/';
        $afterExternal = null;
        $dialogue = $this->wizardImportDialogue('fixture.zip', $url);
        $dialogue[] = ['Type that complete URL', $url];
        $dialogue[] = ['Create the new website and execute this plan now', static function () use (&$afterExternal) {
            self::$sandbox->wp('target', ['site', 'create', '--slug=wizard-claimed', '--title=External fixture site', '--email=admin@company.example']);
            $afterExternal = self::$sandbox->fixture('target', 'state');
            return 'yes';
        }];
        $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import'], $dialogue);
        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('destination site already exists', $result['stdout']);
        self::assertNotNull($afterExternal);
        self::assertSame($afterExternal, self::$sandbox->fixture('target', 'state'));
        self::assertNull($this->runStatus($this->runId($result))['site_id']);
        $this->assertWorkspaceClean();
    }

    public function testWizardBlocksInvalidPackageAndSingleSiteBeforeConfirmation(): void
    {
        self::$sandbox->exportPackage();
        $before = self::$sandbox->fixture('target', 'state');
        $package = $this->modifiedPackage(static function ($zip) { $zip->addFromString('tables.sql', 'corrupt'); }, false);
        $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import'], $this->wizardImportDialogue($package, 'http://target.test/wizard-corrupt/'));
        self::assertNotSame(0, $result['code']);
        self::assertStringNotContainsString('Type that complete URL', $result['stdout']);
        self::assertSame($before, self::$sandbox->fixture('target', 'state'));
        $single = self::$sandbox->fixture('single', 'state');
        $result = self::$sandbox->terminal('single', ['rrze-migration', 'wizard', 'import'], []);
        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('multisite destination', $result['stdout']);
        self::assertSame($single, self::$sandbox->fixture('single', 'state'));
        $this->assertWorkspaceClean();
    }

    public function testWizardRequiresSeparateConsentWhenMediaAreMissing(): void
    {
        self::$sandbox->exportPackage();
        $package = $this->modifiedPackage(static function ($zip) {
            $meta = json_decode($zip->getFromName('site.json'), true, 512, JSON_THROW_ON_ERROR);
            $meta['uploads_included'] = false;
            foreach (array_keys($meta['files']) as $name) {
                if (str_starts_with($name, 'wp-content/uploads/')) {
                    $zip->deleteName($name);
                }
            }
            $zip->addFromString('site.json', json_encode($meta, JSON_THROW_ON_ERROR));
        });
        $before = self::$sandbox->fixture('target', 'state');
        $dialogue = $this->wizardImportDialogue($package, 'http://target.test/wizard-no-media/');
        $dialogue[] = ['Media are absent; a separate transfer is not verified. Continue', ''];
        $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import'], $dialogue);
        self::assertNotSame(0, $result['code']);
        self::assertSame(6, $result['answers']);
        self::assertStringNotContainsString('Type that complete URL', $result['stdout']);
        self::assertSame($before, self::$sandbox->fixture('target', 'state'));
        self::assertNull($this->runStatus($this->runId($result))['site_id']);
        $this->assertWorkspaceClean();
    }

    public function testLargeSqlValueSurvivesPreflightAndImportWithoutChangingOtherSites(): void
    {
        $source = self::$sandbox->fixture('source', 'snapshot', ['2']);
        $control = self::$sandbox->fixture('target', 'snapshot', ['2']);
        // Varied data keeps the package below the compression-ratio limit.
        $value = '';
        for ($index = 0; $index < 12000; $index++) {
            $value .= hash('sha256', (string) $index) . " Grüße \\ ' \" # -- /* INSERT INTO `foreign`; ";
        }
        $serialized = serialize(['large' => $value, 'nested' => ['retained' => true]]);
        self::assertGreaterThan(1000000, strlen($serialized));
        $package = $this->modifiedPackage(static function (ZipArchive $zip) use ($serialized): void {
            $literal = str_replace(['\\', "'"], ['\\\\', "\\'"], $serialized);
            $sql = "\nINSERT INTO `src_2_options` (`option_name`,`option_value`,`autoload`) VALUES ('rrze_large_sql_fixture','$literal','no');\n";
            $zip->addFromString('tables.sql', $zip->getFromName('tables.sql') . $sql);
        });
        $url = 'http://target.test/long-sql-value/';
        $args = ['rrze-migration', 'import', 'all', $package, '--new_url=' . $url, '--uid_fields=_fixture_user'];
        $before = self::$sandbox->fixture('target', 'state');
        $files = $this->uploadsSnapshot();
        $plan = json_decode(self::$sandbox->wp('target', [...$args, '--dry-run', '--format=json']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('create_new_site', $plan['action']);
        self::assertSame($before, self::$sandbox->fixture('target', 'state'));
        self::assertSame($files, $this->uploadsSnapshot());
        $result = self::$sandbox->command('target', $args);
        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertSame('completed', $this->runStatus($this->runId($result))['observed_status']);
        $hash = trim(self::$sandbox->wp('target', ['eval', "echo hash('sha256', serialize(get_option('rrze_large_sql_fixture')));", '--url=' . $url]));
        self::assertSame(hash('sha256', $serialized), $hash);
        self::assertSame($source, self::$sandbox->fixture('source', 'snapshot', ['2']));
        self::assertSame($control, self::$sandbox->fixture('target', 'snapshot', ['2']));
        $this->assertWorkspaceClean();
    }

    public function testUnterminatedSqlTokensAreRejectedBeforeCreatingASite(): void
    {
        foreach (["SELECT 'synthetic-secret", '/* synthetic-secret', "/*!40000 SELECT 'synthetic-secret */;"] as $index => $broken) {
            $package = $this->modifiedPackage(static function (ZipArchive $zip) use ($broken): void {
                $zip->addFromString('tables.sql', $zip->getFromName('tables.sql') . "\n" . $broken);
            });
            $files = $this->uploadsSnapshot();
            $this->assertRejected($package, 'http://target.test/unterminated-sql-' . $index . '/', 'Unterminated');
            self::assertSame($files, $this->uploadsSnapshot());
        }
    }

    public function testWizardUploadLimitationRequiresConsentForPreviewAndImport(): void
    {
        self::$sandbox->exportPackage();
        $hook = $this->customUploadHook();
        try {
            $before = self::$sandbox->fixture('target', 'state');
            $files = $this->uploadsSnapshot();
            foreach (['preview', 'import'] as $action) {
                foreach (['', '!quit', "\x04"] as $answer) {
                    $dialogue = $this->wizardImportDialogue('fixture.zip', 'http://target.test/manual-cancel/');
                    $dialogue[4][1] = $action;
                    $dialogue[] = ['Continue without uploads', $answer];
                    $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import'], $dialogue);
                    self::assertNotSame(0, $result['code']);
                    self::assertStringContainsString('Upload limitation:', $result['stdout']);
                    self::assertStringContainsString('Media and custom upload locations are not verified', $result['stdout']);
                    self::assertStringNotContainsString('Type that complete URL', $result['stdout']);
                    self::assertSame($before, self::$sandbox->fixture('target', 'state'));
                    self::assertSame($files, $this->uploadsSnapshot());
                    if ($action === 'import') {
                        self::assertNull($this->runStatus($this->runId($result))['site_id']);
                    }
                    $this->assertWorkspaceClean();
                }
            }
            $runs = glob(self::$sandbox->root . '/runs-target/*');
            $dialogue = $this->wizardImportDialogue('fixture.zip', 'http://target.test/manual-preview/');
            $dialogue[4][1] = '';
            $dialogue[] = ['Continue without uploads', 'yes'];
            $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import'], $dialogue);
            self::assertSame(0, $result['code'], $result['stdout']);
            self::assertStringContainsString('Media transfer: SKIPPED', $result['stdout']);
            self::assertStringContainsString('Dry-run complete', $result['stdout']);
            self::assertSame($before, self::$sandbox->fixture('target', 'state'));
            self::assertSame($files, $this->uploadsSnapshot());
            self::assertSame($runs, glob(self::$sandbox->root . '/runs-target/*'));
            $this->assertWorkspaceClean();
        } finally {
            unlink($hook);
        }
    }

    public function testWizardManualUploadImportLeavesMediaForExternalTransfer(): void
    {
        self::$sandbox->exportPackage();
        $source = self::$sandbox->fixture('source', 'snapshot', ['2']);
        $control = self::$sandbox->fixture('target', 'snapshot', ['2']);
        $files = $this->uploadsSnapshot();
        $manual = self::$sandbox->root . '/manual-media';
        mkdir($manual, 0700);
        file_put_contents($manual . '/protected.txt', 'existing custom media');
        $hook = $this->customUploadHook();
        try {
            $url = 'http://target.test/manual-upload-import/';
            $dialogue = $this->wizardImportDialogue('fixture.zip', $url);
            $dialogue[] = ['Continue without uploads', 'yes'];
            $dialogue[] = ['Type that complete URL', $url];
            $dialogue[] = ['Create the new website and execute this plan now', 'yes'];
            $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import'], $dialogue);
            self::assertSame(0, $result['code'], $result['stdout']);
            self::assertStringContainsString('Site data imported', $result['stdout']);
            self::assertStringContainsString('Uploads were skipped', $result['stdout']);
            self::assertStringNotContainsString('Transferring packaged media', $result['stdout']);
            self::assertStringNotContainsString('All done', $result['stdout']);
            $state = $this->runStatus($this->runId($result));
            self::assertSame('completed', $state['observed_status']);
            self::assertSame('skipped', $state['steps']['import_uploads']['status']);
            self::assertSame('manual_transfer', $state['steps']['import_uploads']['reason']);
            self::assertTrue($state['uploads']['included']);
            self::assertTrue($state['uploads']['skipped']);
            self::assertTrue($state['uploads']['manual_transfer_required']);
            self::assertFalse($state['uploads']['transfer']);
            self::assertFalse($state['uploads']['verified']);
            self::assertSame(0, $state['uploaded_files']);
            self::assertNull($state['uploads_directory']);
            self::assertStringContainsString('Media and custom upload locations are not verified', $state['media_notice']);
            $status = self::$sandbox->command('target', ['rrze-migration', 'status', $state['run_id']]);
            self::assertStringContainsString('Uploads are skipped', $status['stdout'] . $status['stderr']);
            $content = self::$sandbox->fixture('target', 'content', [(string) $state['site_id']]);
            self::assertSame('Migration fixture', $content['title']);
            self::assertSame($url . 'about/', $content['option']['url']);
            self::assertNull($content['media_hash']);
            $guid = self::$sandbox->wp('target', ['post', 'get', (string) $content['attachment'], '--field=guid', '--url=' . $url]);
            self::assertStringContainsString('wp-content/uploads/sites/2/', $guid);
            self::assertSame($files, $this->uploadsSnapshot());
            self::assertSame([$manual . '/protected.txt'], glob($manual . '/*'));
            self::assertSame('existing custom media', file_get_contents($manual . '/protected.txt'));
            self::assertSame($source, self::$sandbox->fixture('source', 'snapshot', ['2']));
            self::assertSame($control, self::$sandbox->fixture('target', 'snapshot', ['2']));
            $this->assertWorkspaceClean();
        } finally {
            unlink($hook);
        }
    }

    public function testExplicitSkipUploadsWorksWithCustomAndLegacyLayouts(): void
    {
        self::$sandbox->exportPackage();
        $hook = self::$sandbox->root . '/target/wp-content/mu-plugins/upload-layout.php';
        foreach ([
            "add_filter('pre_option_upload_path', static fn () => 'custom/uploads');",
            "add_filter('pre_site_option_ms_files_rewriting', '__return_true');",
            "define('UPLOADS', 'custom/uploads');",
            "define('BLOGUPLOADDIR', ABSPATH . 'custom/uploads/');",
        ] as $configuration) {
            file_put_contents($hook, '<?php ' . $configuration);
            try {
                $before = self::$sandbox->fixture('target', 'state');
                $files = $this->uploadsSnapshot();
                $args = ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/manual-layout/', '--dry-run', '--format=json'];
                $result = self::$sandbox->command('target', $args);
                self::assertNotSame(0, $result['code']);
                self::assertStringContainsString('separately supported migration adapter', $result['stderr']);
                $result = self::$sandbox->command('target', [...$args, '--no-skip-uploads']);
                self::assertNotSame(0, $result['code']);
                self::assertStringContainsString('separately supported migration adapter', $result['stderr']);
                $plan = json_decode(self::$sandbox->wp('target', [...$args, '--skip-uploads']), true, 512, JSON_THROW_ON_ERROR);
                self::assertTrue($plan['uploads']['skipped']);
                self::assertFalse($plan['uploads']['transfer']);
                self::assertFalse($plan['uploads']['verified']);
                self::assertNull($plan['destination_details']['uploads_directory']);
                self::assertNull($plan['destination_details']['storage']);
                self::assertSame($before, self::$sandbox->fixture('target', 'state'));
                self::assertSame($files, $this->uploadsSnapshot());
                $this->assertWorkspaceClean();
            } finally {
                unlink($hook);
            }
        }
    }

    public function testSkipUploadsCannotBypassExistingSitesLeftoversOrPackageIntegrity(): void
    {
        self::$sandbox->exportPackage();
        $args = ['rrze-migration', 'import', 'all', 'fixture.zip', '--skip-uploads'];
        $before = self::$sandbox->fixture('target', 'state');
        $result = self::$sandbox->command('target', [...$args, '--new_url=http://target.test/control/']);
        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('already exists', $result['stderr']);
        self::assertSame($before, self::$sandbox->fixture('target', 'state'));
        foreach (['table', 'files', 'membership'] as $kind) {
            self::$sandbox->fixture('target', 'leftover', [$kind, 'create']);
            try {
                $before = self::$sandbox->fixture('target', 'state');
                $files = $this->uploadsSnapshot();
                $result = self::$sandbox->command('target', [...$args, '--new_url=http://target.test/manual-conflict/']);
                self::assertNotSame(0, $result['code']);
                self::assertStringContainsString('Pre-existing', $result['stderr']);
                self::assertSame($before, self::$sandbox->fixture('target', 'state'));
                self::assertSame($files, $this->uploadsSnapshot());
            } finally {
                self::$sandbox->fixture('target', 'leftover', [$kind, 'remove']);
            }
        }
        $package = $this->modifiedPackage(static function (ZipArchive $zip): void {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (str_ends_with($name, '/fixture.png')) {
                    $zip->addFromString($name, 'corrupt media');
                }
            }
        }, false);
        $before = self::$sandbox->fixture('target', 'state');
        $hook = $this->customUploadHook();
        try {
            $result = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', $package, '--skip-uploads', '--new_url=http://target.test/manual-corrupt/']);
            self::assertNotSame(0, $result['code']);
            $dialogue = $this->wizardImportDialogue($package, 'http://target.test/manual-corrupt/');
            $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import'], $dialogue);
            self::assertNotSame(0, $result['code']);
            self::assertStringNotContainsString('Continue without uploads', $result['stdout']);
            self::assertSame($before, self::$sandbox->fixture('target', 'state'));
            $this->assertWorkspaceClean();
        } finally {
            unlink($hook);
        }
    }

    public function testExplicitSkipUploadsImportsSiteDataOnStandardLayout(): void
    {
        self::$sandbox->exportPackage();
        $files = $this->uploadsSnapshot();
        $result = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', 'fixture.zip', '--new_url=http://target.test/manual-standard/', '--uid_fields=_fixture_user', '--skip-uploads']);
        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('Uploads were skipped', $result['stdout']);
        $state = $this->runStatus($this->runId($result));
        self::assertSame('completed', $state['observed_status']);
        self::assertSame('skipped', $state['steps']['import_uploads']['status']);
        self::assertFalse($state['uploads']['verified']);
        self::assertSame(0, $state['uploaded_files']);
        self::assertSame($files, $this->uploadsSnapshot());
        self::assertDirectoryDoesNotExist(self::$sandbox->root . '/target/wp-content/uploads/sites/' . $state['site_id']);
        $this->assertWorkspaceClean();
    }

    public function testUploadChangesDuringReviewCannotInheritApproval(): void
    {
        self::$sandbox->exportPackage();
        foreach (['limitation', 'leftover'] as $kind) {
            $hook = $kind === 'leftover' ? $this->customUploadHook() : self::$sandbox->root . '/target/wp-content/mu-plugins/late-upload-layout.php';
            if ($kind === 'limitation') {
                file_put_contents($hook, <<<'PHPHOOK'
<?php
add_action('rrze_migration_before_step', static function ($step) {
    if ($step === 'recheck') {
        add_filter('upload_dir', '__return_empty_array');
    }
});
PHPHOOK);
            }
            $after = null;
            $files = null;
            try {
                $url = 'http://target.test/manual-late-' . $kind . '/';
                $dialogue = $this->wizardImportDialogue('fixture.zip', $url);
                if ($kind === 'leftover') {
                    $dialogue[] = ['Continue without uploads', 'yes'];
                }
                $dialogue[] = ['Type that complete URL', $url];
                $dialogue[] = ['Create the new website and execute this plan now', function () use ($kind, &$after, &$files) {
                    if ($kind === 'leftover') {
                        self::$sandbox->fixture('target', 'leftover', ['files', 'create']);
                    }
                    $after = self::$sandbox->fixture('target', 'state');
                    $files = $this->uploadsSnapshot();
                    return 'yes';
                }];
                $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import'], $dialogue);
                self::assertNotSame(0, $result['code']);
                self::assertStringContainsString($kind === 'limitation' ? 'migration adapter' : 'Pre-existing uploads', $result['stdout']);
                self::assertSame($kind === 'limitation' ? 0 : 1, substr_count($result['stdout'], 'Continue without uploads'));
                self::assertNull($this->runStatus($this->runId($result))['site_id']);
                self::assertSame($after, self::$sandbox->fixture('target', 'state'));
                self::assertSame($files, $this->uploadsSnapshot());
                $this->assertWorkspaceClean();
            } finally {
                if ($kind === 'leftover' && $after !== null) {
                    self::$sandbox->fixture('target', 'leftover', ['files', 'remove']);
                }
                if ($hook !== null) {
                    unlink($hook);
                }
            }
        }
    }

    public function testExportAndImportSharePrivateStorageWithIndependentSubdirectories(): void
    {
        self::$sandbox->exportPackage();
        $source = self::$sandbox->fixture('source', 'snapshot', ['2']);
        $control = self::$sandbox->fixture('target', 'snapshot', ['2']);
        $root = self::$sandbox->root . '/shared-migrations';
        self::$sandbox->wp('source', ['rrze-migration', 'export', 'all', 'shared package', '--run-dir=' . $root, '--uploads', '--url=http://source.test/source/']);
        $packages = glob($root . '/export-*/shared package.zip');
        self::assertCount(1, $packages);
        $package = $packages[0];
        $hash = hash_file('sha256', $package);
        self::assertSame(0700, fileperms($root) & 0777);
        self::assertSame(0700, fileperms(dirname($package)) & 0777);
        self::assertSame(0600, fileperms($package) & 0777);
        self::assertFileDoesNotExist(self::$sandbox->root . '/source/shared package.zip');
        $relative = substr($package, strlen($root) + 1);
        $url = 'http://target.test/shared-private-storage/';
        $args = ['rrze-migration', 'import', 'all', $relative, '--run-dir=' . $root, '--new_url=' . $url, '--uid_fields=_fixture_user'];
        $before = self::$sandbox->fixture('target', 'state');
        $files = glob($root . '/*');
        self::$sandbox->wp('target', [...$args, '--dry-run']);
        self::assertSame($files, glob($root . '/*'));
        self::assertSame($before, self::$sandbox->fixture('target', 'state'));
        $result = self::$sandbox->command('target', $args);
        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        $id = $this->runId($result);
        $state = json_decode(self::$sandbox->wp('target', ['rrze-migration', 'status', $id, '--run-dir=' . $root, '--format=json']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('completed', $state['observed_status']);
        self::assertSame($root . '/' . $id . '/package.zip', $state['preserved_package']);
        self::assertSame($hash, hash_file('sha256', $state['preserved_package']));
        self::assertSame($hash, hash_file('sha256', $package));
        self::assertSame(0600, fileperms($state['preserved_package']) & 0777);
        self::assertSame($source, self::$sandbox->fixture('source', 'snapshot', ['2']));
        self::assertSame($control, self::$sandbox->fixture('target', 'snapshot', ['2']));
        $this->assertWorkspaceClean();
    }

    public function testWebRootPackagesAreRejectedWithoutMovingOrDeletingTheOriginal(): void
    {
        $original = self::$sandbox->exportPackage();
        $sourcePublic = self::$sandbox->root . '/source/protected-public.zip';
        $targetPublic = self::$sandbox->root . '/target/public-input.zip';
        copy($original, $sourcePublic);
        copy($original, $targetPublic);
        $link = self::$sandbox->root . '/runs-target/public-alias';
        symlink(self::$sandbox->root . '/target', $link);
        $hash = hash_file('sha256', $original);
        try {
            $before = self::$sandbox->fixture('target', 'state');
            $runs = glob(self::$sandbox->root . '/runs-target/*');
            foreach ([$targetPublic, 'public-alias/public-input.zip', 'public-input.zip'] as $input) {
                foreach ([[], ['--dry-run']] as $mode) {
                    $result = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', $input, '--new_url=http://target.test/public-input/', ...$mode]);
                    self::assertNotSame(0, $result['code']);
                    self::assertStringContainsString($input === 'public-input.zip' ? 'readable local ZIP' : 'inside WordPress or wp-content', $result['stderr']);
                    self::assertSame($runs, glob(self::$sandbox->root . '/runs-target/*'));
                    self::assertSame($hash, hash_file('sha256', $targetPublic));
                    self::assertSame($before, self::$sandbox->fixture('target', 'state'));
                }
            }
            $exports = glob(self::$sandbox->root . '/runs-source/export-*');
            $result = self::$sandbox->command('source', ['rrze-migration', 'export', 'all', $sourcePublic, '--url=http://source.test/source/']);
            self::assertNotSame(0, $result['code']);
            self::assertStringContainsString('filename without a directory', $result['stderr']);
            self::assertSame($hash, hash_file('sha256', $sourcePublic));
            self::assertSame($exports, glob(self::$sandbox->root . '/runs-source/export-*'));
            $publicRoot = self::$sandbox->root . '/source/wp-content/forbidden-migrations';
            $result = self::$sandbox->command('source', ['rrze-migration', 'export', 'all', 'blocked.zip', '--run-dir=' . $publicRoot, '--url=http://source.test/source/']);
            self::assertNotSame(0, $result['code']);
            self::assertStringContainsString('outside the web roots', $result['stderr']);
            self::assertDirectoryDoesNotExist($publicRoot);
            $this->assertWorkspaceClean();
        } finally {
            unlink($link);
            unlink($sourcePublic);
            unlink($targetPublic);
        }
    }

    public function testExplicitUploadExclusionPreservesSourceProtectionFilesAndRecordsOmissions(): void
    {
        $uploads = self::$sandbox->root . '/source/wp-content/uploads/sites/2';
        mkdir($uploads . '/wp-migrate-db', 0700);
        mkdir($uploads . '/wp-migrate-db-keep', 0700);
        file_put_contents($uploads . '/wp-migrate-db/.htaccess', 'Deny from all');
        file_put_contents($uploads . '/wp-migrate-db/index.php', '<?php // Synthetic protection file');
        file_put_contents($uploads . '/wp-migrate-db/backup.sql', 'synthetic backup data');
        file_put_contents($uploads . '/wp-migrate-db-keep/keep.txt', 'ordinary upload');
        $source = self::$sandbox->fixture('source', 'snapshot', ['2']);
        $sourceUrl = trim(self::$sandbox->wp('source', ['option', 'get', 'home', '--url=http://source.test/source/']));
        $exports = glob(self::$sandbox->root . '/runs-source/export-*');
        $result = self::$sandbox->command('source', ['rrze-migration', 'export', 'all', 'blocked.zip', '--uploads', '--url=http://source.test/source/']);
        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('wp-content/uploads/wp-migrate-db/.htaccess', $result['stderr']);
        self::assertFileDoesNotExist(self::$sandbox->root . '/source/blocked.zip');
        self::assertSame($exports, glob(self::$sandbox->root . '/runs-source/export-*'));
        $this->assertWorkspaceClean();
        $result = self::$sandbox->command('source', ['rrze-migration', 'export', 'all', 'invalid-exclusion.zip', '--exclude-upload-dirs=wp-migrate-db', '--url=http://source.test/source/']);
        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('requires --uploads', $result['stderr']);
        self::assertFileDoesNotExist(self::$sandbox->root . '/source/invalid-exclusion.zip');
        self::$sandbox->wp('source', ['rrze-migration', 'export', 'all', 'direct-excluded.zip', '--uploads', '--exclude-upload-dirs=wp-migrate-db', '--url=http://source.test/source/']);
        $result = self::$sandbox->terminal('source', ['rrze-migration', 'wizard', 'export', '--url=http://source.test/source/'], [
            ['Source website ID', ''], ['Export this website', 'yes'],
            ['Private migration directory', ''],
            ['New ZIP filename', 'excluded.zip'], ['Additional site-owned tables', ''], ['Include uploads', 'yes'],
            ['Upload subdirectories to exclude', 'wp-migrate-db'], ['Type that complete URL', $sourceUrl], ['Create this export package now', 'yes'],
        ]);
        self::assertSame(0, $result['code'], $result['stdout']);
        self::assertSame(9, $result['answers']);
        self::assertStringContainsString('Excluded upload directories: wp-migrate-db', $result['stdout']);
        $packages = glob(self::$sandbox->root . '/runs-source/export-*/excluded.zip');
        self::assertCount(1, $packages);
        $file = self::$sandbox->root . '/runs-target/excluded.zip';
        self::assertTrue(copy($packages[0], $file));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file));
        $meta = json_decode($zip->getFromName('site.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['wp-migrate-db'], $meta['excluded_upload_directories']);
        self::assertFalse($zip->locateName('wp-content/uploads/wp-migrate-db/.htaccess'));
        self::assertFalse($zip->locateName('wp-content/uploads/wp-migrate-db/index.php'));
        self::assertFalse($zip->locateName('wp-content/uploads/wp-migrate-db/backup.sql'));
        self::assertSame('ordinary upload', $zip->getFromName('wp-content/uploads/wp-migrate-db-keep/keep.txt'));
        $zip->close();
        $targetBefore = self::$sandbox->fixture('target', 'state');
        $control = self::$sandbox->fixture('target', 'snapshot', ['2']);
        $url = 'http://target.test/explicit-exclusion/';
        $plan = json_decode(self::$sandbox->wp('target', ['rrze-migration', 'import', 'all', 'excluded.zip', '--new_url=' . $url, '--dry-run', '--format=json']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['wp-migrate-db'], $plan['uploads']['excluded_directories']);
        $dialogue = $this->wizardImportDialogue('excluded.zip', $url);
        $dialogue[] = ['Listed upload directories were excluded and will not be restored. Continue', ''];
        $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import'], $dialogue);
        self::assertNotSame(0, $result['code']);
        self::assertSame(6, $result['answers']);
        self::assertSame($targetBefore, self::$sandbox->fixture('target', 'state'));
        $dialogue[5][1] = 'yes';
        $dialogue[] = ['Type that complete URL', $url];
        $dialogue[] = ['Create the new website and execute this plan now', 'yes'];
        $result = self::$sandbox->terminal('target', ['rrze-migration', 'wizard', 'import'], $dialogue);
        self::assertSame(0, $result['code'], $result['stdout']);
        self::assertSame(8, $result['answers']);
        $state = $this->runStatus($this->runId($result));
        self::assertSame('completed', $state['observed_status']);
        $content = self::$sandbox->fixture('target', 'content', [(string) $state['site_id']]);
        $original = self::$sandbox->fixture('source', 'content', ['2']);
        self::assertSame($original['media_hash'], $content['media_hash']);
        self::assertSame('ordinary upload', file_get_contents($state['uploads_directory'] . '/wp-migrate-db-keep/keep.txt'));
        self::assertDirectoryDoesNotExist($state['uploads_directory'] . '/wp-migrate-db');
        self::assertSame($source, self::$sandbox->fixture('source', 'snapshot', ['2']));
        self::assertSame($control, self::$sandbox->fixture('target', 'snapshot', ['2']));
        $this->assertWorkspaceClean();
    }

    private function customUploadHook(): string
    {
        $hook = self::$sandbox->root . '/target/wp-content/mu-plugins/custom-uploads.php';
        file_put_contents($hook, <<<'PHPHOOK'
<?php
add_filter('upload_dir', static function ($uploads) {
    if (get_current_blog_id() > 2) {
        $uploads['basedir'] = dirname(rtrim(ABSPATH, '/')) . '/manual-media';
        $uploads['baseurl'] = 'http://media.test/custom';
        $uploads['path'] = $uploads['basedir'] . $uploads['subdir'];
        $uploads['url'] = $uploads['baseurl'] . $uploads['subdir'];
    }
    return $uploads;
});
PHPHOOK);
        return $hook;
    }

    private function wizardImportDialogue(string $file, string $url): array
    {
        return [
            ['Private migration directory', ''],
            ['ZIP package (relative', $file], ['New destination URL', $url],
            ['Post meta keys', '_fixture_user'], ['Next action', 'import'],
        ];
    }

    private function cleanupInterruptedWorkspace(array $state): void
    {
        // Only fixture processes already known to have exited with no active child command.
        self::assertSame(self::$sandbox->root, dirname($state['workspace']));
        self::assertMatchesRegularExpression('/^rrze-migration-[a-f0-9]{32}$/D', basename($state['workspace']));
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($state['workspace'], FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($state['workspace']);
        $this->assertWorkspaceClean();
    }

    private function assertRejectedAfterKill(array $state): void
    {
        $result = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', $state['preserved_package'], '--new_url=http://target.test/hard-kill/']);
        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('already exists', $result['stderr']);
    }

    private function runId(array $result): string
    {
        self::assertMatchesRegularExpression('/Migration run: [a-f0-9]{32}/', $result['stdout']);
        preg_match('/Migration run: ([a-f0-9]{32})/', $result['stdout'], $match);
        return $match[1];
    }

    private function runStatus(string $id, ?string $root = null): array
    {
        $options = $root === null ? [] : ['--run-dir=' . $root];
        return json_decode(self::$sandbox->wp('target', ['rrze-migration', 'status', $id, '--format=json', ...$options]), true, 64, JSON_THROW_ON_ERROR);
    }

    private function pauseHook(string $step): array
    {
        $root = self::$sandbox->root;
        $hook = $root . '/target/wp-content/mu-plugins/pause-run.php';
        $ready = $root . '/ready-' . $step;
        $release = $root . '/release-' . $step;
        $code = '<?php add_action("rrze_migration_before_step", static function ($step, $id) {'
            . 'if ($step !== ' . var_export($step, true) . ') { return; }'
            . 'file_put_contents(' . var_export($ready, true) . ', $id); $deadline = microtime(true) + 45;'
            . 'while (!file_exists(' . var_export($release, true) . ')) { if (microtime(true) > $deadline) { throw new RuntimeException("Fixture pause timed out"); } usleep(20000); clearstatcache(); }'
            . '}, 10, 2);';
        file_put_contents($hook, $code);
        return [$hook, $ready, $release];
    }

    private function awaitMarker(string $file): void
    {
        $deadline = microtime(true) + 45;
        while (!is_file($file)) {
            if (microtime(true) > $deadline) {
                self::fail('Fixture process did not reach its checkpoint.');
            }
            usleep(20000);
            clearstatcache();
        }
    }

    private function uploadsSnapshot(): array
    {
        $root = self::$sandbox->root . '/target/wp-content/uploads';
        $result = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $result[substr($file->getPathname(), strlen($root))] = $file->isDir() ? 'directory' : hash_file('sha256', $file->getPathname());
        }
        ksort($result);
        return $result;
    }

    private function assertRejected(string $package, string $url, string $message = ''): void
    {
        $before = self::$sandbox->fixture('target', 'state');
        $result = self::$sandbox->command('target', ['rrze-migration', 'import', 'all', $package, '--new_url=' . $url]);
        self::assertNotSame(0, $result['code'], $result['stdout']);
        self::assertStringNotContainsString('All done', $result['stdout']);
        if ($message !== '') {
            self::assertStringContainsString($message, $result['stderr']);
        }
        self::assertSame($before, self::$sandbox->fixture('target', 'state'));
        $this->assertWorkspaceClean();
    }

    private function assertWorkspaceClean(): void
    {
        self::assertSame([], glob(self::$sandbox->root . '/rrze-migration-*'));
    }

    private function modifiedPackage(callable $modify, bool $refreshManifest = true): string
    {
        $original = self::$sandbox->exportPackage();
        $name = 'modified-' . bin2hex(random_bytes(5)) . '.zip';
        $path = self::$sandbox->root . '/runs-target/' . $name;
        self::assertTrue(copy($original, $path));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        try {
            $modify($zip);
            if ($refreshManifest) {
                $meta = json_decode($zip->getFromName('site.json'), true);
                if (is_array($meta)) {
                    $meta['files'] = [];
                    for ($index = 0; $index < $zip->numFiles; $index++) {
                        $entry = $zip->getNameIndex($index);
                        if ($entry !== false && $entry !== 'site.json' && !str_ends_with($entry, '/')) {
                            $data = $zip->getFromName($entry);
                            $meta['files'][$entry] = ['bytes' => strlen($data), 'sha256' => hash('sha256', $data)];
                        }
                    }
                    $zip->addFromString('site.json', json_encode($meta, JSON_THROW_ON_ERROR));
                }
            }
        } finally {
            self::assertTrue($zip->close());
        }
        return $name;
    }

    private function rewriteCsv(ZipArchive $zip, callable $modify): void
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $zip->getFromName('users.csv'));
        rewind($stream);
        $headers = fgetcsv($stream, 0, ',', '"', '\\');
        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            $rows[] = $modify(array_combine($headers, $row));
        }
        ftruncate($stream, 0);
        rewind($stream);
        fputcsv($stream, array_keys($rows[0]), ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($stream, array_values($row), ',', '"', '\\');
        }
        rewind($stream);
        $zip->addFromString('users.csv', stream_get_contents($stream));
        fclose($stream);
    }
}
