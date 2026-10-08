<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RRZE\CLI\Migration\{Tables, Users, SiteAddress};

final class MigrationPolicyTest extends TestCase
{
    private const AVAILABLE = ['wp_posts', 'wp_options', 'wp_users', 'wp_usermeta', 'wp_blogs', 'wp_2_posts', 'wp_2_options', 'wp_2_custom', 'wp_shared'];
    private const GLOBAL = ['wp_users', 'wp_usermeta', 'wp_blogs'];

    public function testMainSiteDefaultsExcludeGlobalAndOtherSiteTables(): void
    {
        self::assertSame(['wp_options', 'wp_posts'], Tables::select(self::AVAILABLE, ['wp_posts', 'wp_options'], self::GLOBAL, 'wp_', 'wp_'));
    }

    public function testCustomTablesAreAdditiveAndDeduplicated(): void
    {
        self::assertSame(['wp_options', 'wp_posts', 'wp_shared'], Tables::select(self::AVAILABLE, ['wp_posts', 'wp_options'], self::GLOBAL, 'wp_', 'wp_', '', 'wp_shared,wp_posts'));
    }

    public function testSubsiteDefaultsIncludeItsOwnCustomTables(): void
    {
        self::assertSame(['wp_2_custom', 'wp_2_options', 'wp_2_posts'], Tables::select(self::AVAILABLE, ['wp_2_posts', 'wp_2_options'], self::GLOBAL, 'wp_2_', 'wp_'));
    }

    #[DataProvider('unsafeTables')]
    public function testExplicitSelectionCannotBypassOwnership(string $requested): void
    {
        $this->expectException(RuntimeException::class);
        Tables::select(self::AVAILABLE, ['wp_posts'], self::GLOBAL, 'wp_', 'wp_', '', $requested);
    }

    public static function unsafeTables(): iterable
    {
        foreach (['wp_users', 'wp_usermeta', 'wp_blogs', 'wp_2_posts', 'wp_missing', 'wp_posts;DROP TABLE wp_users', 'wp_posts,'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('identityConflicts')]
    public function testAnEmailMatchNeverOverridesTheSsoIdentity(array $source, array $targets): void
    {
        $this->expectException(RuntimeException::class);
        Users::resolve($source, $targets);
    }

    public static function identityConflicts(): iterable
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/user-conflicts.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as $case) {
            yield $case['name'] => [$case['source'], $case['targets'] ?? [$case['target']]];
        }
        yield 'ambiguous duplicate target' => [
            ['user_login' => 'sso1', 'user_email' => 'sso1@company.example'],
            [['ID' => 4, 'user_login' => 'sso1', 'user_email' => 'sso1@company.example'], ['ID' => 5, 'user_login' => 'sso1', 'user_email' => 'sso1@company.example']],
        ];
        yield 'login case mismatch requires clarification' => [
            ['user_login' => 'SSO1', 'user_email' => 'sso1@company.example'],
            [['ID' => 4, 'user_login' => 'sso1', 'user_email' => 'sso1@company.example']],
        ];
    }

    public function testConfirmedIdentityUsesTargetIdAndMissingIdentityRemainsUnmapped(): void
    {
        $source = ['user_login' => 'sso1', 'user_email' => 'sso1@company.example'];
        self::assertSame(17, Users::resolve($source, [['ID' => 17, 'user_login' => 'sso1', 'user_email' => 'SSO1@company.example']]));
        self::assertNull(Users::resolve($source, []));
    }

    public function testCustomHeadersCannotReintroduceCredentialsOrGlobalPermissions(): void
    {
        $forbidden = ['user_pass', 'user_activation_key', '_application_passwords', 'session_tokens', 'wp_capabilities', 'wp_2_user_level', 'primary_blog', 'source_domain', 'site_admins'];
        $headers = Users::headers([...$forbidden, 'display_name', 'custom_safe']);
        self::assertSame([], array_values(array_intersect($forbidden, $headers)));
        self::assertSame([...Users::HEADERS, 'custom_safe'], $headers);
    }

    #[DataProvider('invalidCsv')]
    public function testMalformedOrContradictoryCsvFailsBeforeItCanProduceAPlan(string $csv): void
    {
        $file = tempnam(sys_get_temp_dir(), 'rrze-csv-');
        try {
            file_put_contents($file, $csv);
            $this->expectException(RuntimeException::class);
            Users::read($file);
        } finally {
            unlink($file);
        }
    }

    public static function invalidCsv(): iterable
    {
        $header = "ID,user_login,user_email,role\n";
        yield 'missing identity column' => ["ID,role\n1,editor\n"];
        yield 'duplicate header' => ["ID,user_login,user_email,role,role\n"];
        yield 'wrong column count' => [$header . "1,sso1,sso1@company.example\n"];
        yield 'invalid ID' => [$header . "0,sso1,sso1@company.example,editor\n"];
        yield 'invalid email' => [$header . "1,sso1,invalid,editor\n"];
        yield 'missing role' => [$header . "1,sso1,sso1@company.example,\n"];
        yield 'reference only with role' => ["ID,user_login,user_email,role,site_member\n1,sso1,sso1@company.example,editor,0\n"];
        yield 'member without role' => ["ID,user_login,user_email,role,site_member\n1,sso1,sso1@company.example,,1\n"];
        yield 'invalid membership flag' => ["ID,user_login,user_email,role,site_member\n1,sso1,sso1@company.example,editor,yes\n"];
        yield 'duplicate ID' => [$header . "1,sso1,sso1@company.example,editor\n1,sso2,sso2@company.example,author\n"];
        yield 'duplicate login case' => [$header . "1,sso1,sso1@company.example,editor\n2,SSO1,sso2@company.example,author\n"];
        yield 'duplicate email' => [$header . "1,sso1,sso1@company.example,editor\n2,sso2,sso1@company.example,author\n"];
    }

    public function testLegacyCsvCredentialsAreDiscarded(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'rrze-csv-');
        try {
            file_put_contents($file, "ID,user_login,user_email,role,user_pass,user_activation_key,session_tokens,_application_passwords,wp_capabilities\n21,sso1,sso1@company.example,author,secret,secret,secret,secret,secret\n");
            self::assertSame([['ID' => '21', 'user_login' => 'sso1', 'user_email' => 'sso1@company.example', 'role' => 'author']], Users::read($file));
        } finally {
            unlink($file);
        }
    }

    public function testReferenceOnlyCsvRequiresAnExplicitMembershipFlagAndNoRole(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'rrze-csv-');
        try {
            file_put_contents($file, "ID,user_login,user_email,role,site_member\n35,reference01,reference01@company.example,,0\n");
            $rows = Users::read($file);
            self::assertFalse(Users::isMember($rows[0]));
            self::assertSame('', $rows[0]['role']);
            self::assertTrue(Users::isMember(['role' => 'editor']));
            Users::requireReferences([0, 35], $rows);
            $this->expectExceptionMessage('source user ID 36');
            Users::requireReferences([35, 36], $rows);
        } finally {
            unlink($file);
        }
    }

    #[DataProvider('siteAddresses')]
    public function testEquivalentSiteUrlsHaveTheSameIdentity(string $url, string $domain, string $path): void
    {
        $address = SiteAddress::parse($url);
        self::assertSame($domain, $address['domain']);
        self::assertSame($path, $address['path']);
    }

    public static function siteAddresses(): iterable
    {
        yield ['http://target.test/control', 'target.test', '/control/'];
        yield ['https://TARGET.test/control/', 'target.test', '/control/'];
        yield ['target.test', 'target.test', '/'];
        yield ['http://target.test:8890/control/', 'target.test:8890', '/control/'];
    }

    #[DataProvider('ambiguousAddresses')]
    public function testAmbiguousSiteUrlsAreRejected(string $url): void
    {
        $this->expectException(RuntimeException::class);
        SiteAddress::parse($url);
    }

    public static function ambiguousAddresses(): iterable
    {
        foreach (['file:///tmp/site', 'http://user:pass@target.test/', 'https://target.test/site?x=1', 'https://target.test/site#x', 'https://target.test/a/../b', 'https://target.test/a//b', 'https://target.test/a%2fb'] as $url) {
            yield [$url];
        }
    }
}
