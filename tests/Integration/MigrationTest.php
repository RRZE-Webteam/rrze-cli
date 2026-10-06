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
        self::$sandbox->exportPackage();
        $hash = hash_file('sha256', self::$sandbox->root . '/source/fixture.zip');
        $result = self::$sandbox->command('source', ['rrze-migration', 'export', 'all', 'fixture.zip', '--url=http://source.test/source/']);
        self::assertNotSame(0, $result['code']);
        self::assertSame($hash, hash_file('sha256', self::$sandbox->root . '/source/fixture.zip'));
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
        unlink(self::$sandbox->root . '/target/' . $name);
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

    private function runStatus(string $id): array
    {
        return json_decode(self::$sandbox->wp('target', ['rrze-migration', 'status', $id, '--format=json']), true, 64, JSON_THROW_ON_ERROR);
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
        $path = self::$sandbox->root . '/target/' . $name;
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
