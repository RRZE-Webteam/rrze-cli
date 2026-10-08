<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RRZE\CLI\Migration\ExportSource;

final class ExportSourceTest extends TestCase
{
    #[DataProvider('validIds')]
    public function testIdsRemainExactIntegers(string|int $value, int $expected): void
    {
        self::assertSame($expected, ExportSource::id($value));
    }

    public static function validIds(): array
    {
        return [['5', 5], [1, 1], [(string) PHP_INT_MAX, PHP_INT_MAX]];
    }

    #[DataProvider('invalidIds')]
    public function testInvalidIdsCannotSilentlySelectAnotherWebsite(mixed $value): void
    {
        $this->expectExceptionMessage('positive numeric website ID');
        ExportSource::id($value);
    }

    public static function invalidIds(): array
    {
        return array_map(static fn ($value) => [$value], ['', '0', '-1', '2.5', '2e1', '2abc', '02', ' 2', '2\n', str_repeat('9', 30), true, null, 2.5, []]);
    }
}
