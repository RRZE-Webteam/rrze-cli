<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RRZE\CLI\Migration\Destination;

final class DestinationSiteIdTest extends TestCase
{
    private mixed $previous;
    private bool $hadDatabase;

    protected function setUp(): void
    {
        $this->hadDatabase = array_key_exists('wpdb', $GLOBALS);
        $this->previous = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->hadDatabase) {
            $GLOBALS['wpdb'] = $this->previous;
        } else {
            unset($GLOBALS['wpdb']);
        }
    }

    public function testCachedMetadataCannotPointAtAnEarlierSiteAndSessionIsRestored(): void
    {
        $database = $GLOBALS['wpdb'] = new SiteIdDatabaseStub(86400);
        self::assertSame(42, Destination::nextSiteId());
        self::assertSame(86400, $database->expiry);
        $database->actualId = 47; // Deleted/skipped IDs must not be inferred from rows.
        self::assertSame(47, Destination::nextSiteId());
        self::assertSame(86400, $database->expiry);
        self::assertSame([0, 86400, 0, 86400], $database->settings);
    }

    #[DataProvider('uncachedServers')]
    public function testServersWithoutACacheNeedNoSessionWrites(?int $expiry): void
    {
        $database = $GLOBALS['wpdb'] = new SiteIdDatabaseStub($expiry);
        self::assertSame(42, Destination::nextSiteId());
        self::assertSame([], $database->settings);
    }

    public static function uncachedServers(): array
    {
        return ['MySQL 5.7 or MariaDB' => [null], 'cache already disabled' => [0]];
    }

    public function testFailedMetadataReadRestoresTheSessionWithoutInventingAnId(): void
    {
        $database = $GLOBALS['wpdb'] = new SiteIdDatabaseStub(86400);
        $database->failStatus = true;
        try {
            Destination::nextSiteId();
            self::fail('Failed metadata reads must stop the migration.');
        } catch (RuntimeException $error) {
            self::assertSame('Cannot determine the next destination site ID.', $error->getMessage());
        }
        self::assertSame(86400, $database->expiry);
    }

    public function testAnUnavailableCacheOverrideMustNotFallBackToStaleMetadata(): void
    {
        $database = $GLOBALS['wpdb'] = new SiteIdDatabaseStub(86400);
        $database->failSet = true;
        $this->expectExceptionMessage('Cannot read uncached destination site IDs.');
        Destination::nextSiteId();
    }
}

/** Simulates the MySQL 8 table-statistics cache, without a database connection. */
final class SiteIdDatabaseStub
{
    public string $blogs = 'test_blogs';
    public string $last_error = '';
    public int $actualId = 42;
    public array $settings = [];
    public bool $failStatus = false;
    public bool $failSet = false;

    public function __construct(public ?int $expiry) {}

    public function prepare(string $query, string $table): string
    {
        return str_replace('%s', "'" . $table . "'", $query);
    }

    public function get_row(string $query): ?object
    {
        $this->last_error = '';
        if ($query === "SHOW SESSION VARIABLES LIKE 'information_schema_stats_expiry'") {
            return $this->expiry === null ? null : (object) ['Value' => (string) $this->expiry];
        }
        if ($query !== "SHOW TABLE STATUS WHERE Name = 'test_blogs'") {
            throw new LogicException('Unexpected metadata query.');
        }
        if ($this->failStatus) {
            $this->last_error = 'fixture read failure';
            return null;
        }
        return (object) ['Auto_increment' => $this->expiry ? 3 : $this->actualId];
    }

    public function query(string $query): int|false
    {
        if (!preg_match('/^SET SESSION information_schema_stats_expiry = ([0-9]+)$/D', $query, $match)) {
            throw new LogicException('Only the current session may be changed.');
        }
        if ($this->failSet) {
            $this->last_error = 'fixture setting failure';
            return false;
        }
        $this->last_error = '';
        $this->settings[] = $this->expiry = (int) $match[1];
        return 0;
    }
}
