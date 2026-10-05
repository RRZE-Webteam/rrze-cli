<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RRZE\CLI\Tests\Process;

require_once dirname(__DIR__) . '/Support/Process.php';

final class FixtureGuardTest extends TestCase
{
    #[DataProvider('unsafeContexts')]
    public function testFixtureRefusesContextsOutsideItsOwnedInstallation(string $definitions): void
    {
        $code = 'class WP_CLI { public static function error($message) { fwrite(STDERR, $message); exit(17); } }'
            . $definitions . 'require $argv[1];';
        $result = Process::run([PHP_BINARY, '-r', $code, dirname(__DIR__) . '/fixtures/site.php'], __DIR__);
        self::assertSame(17, $result['code']);
        self::assertStringContainsString('Fixture refused', $result['stderr']);
    }

    public static function unsafeContexts(): iterable
    {
        yield 'normal WordPress' => [''];
        yield 'non-test database' => ["define('RRZE_CLI_TEST_RUN', str_repeat('a', 24)); define('DB_NAME', 'wordpress');"];
        yield 'missing ownership marker' => ["define('RRZE_CLI_TEST_RUN', str_repeat('a', 24)); define('DB_NAME', 'rrze_cli_test_' . RRZE_CLI_TEST_RUN . '_source'); define('ABSPATH', sys_get_temp_dir() . '/nonexistent-rrze-test/source/');"];
    }
}
