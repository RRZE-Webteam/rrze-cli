<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RRZE\CLI\Migration\Sql;

final class MigrationSqlTest extends TestCase
{
    public function testUserReferencesFollowSchemaAndExplicitColumnOrderWithoutReadingQuotedContent(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `src_posts` (`ID` bigint, `post_content` text, `post_author` bigint, PRIMARY KEY (`ID`));
CREATE TABLE `src_comments` (`user_id` bigint, `comment_content` text);
INSERT INTO `src_posts` VALUES (1,'Fake: ),(1,evil,999); INSERT INTO `src_comments` VALUES (888,\'x\');',35),(2,'it''s "(),"',0),(3,'escaped \\ slash',35);
INSERT INTO `src_posts` (`post_author`, `ID`, `post_content`) VALUES ('21',4,'content');
-- INSERT INTO `src_posts` VALUES (1,'not data',777);
INSERT INTO `src_comments` VALUES (4,'comment'),(0,'anonymous');
SQL;
        self::assertSame([4, 21, 35], Sql::userReferences($sql, 'src_'));
    }

    public function testEmptyCoreTablesHaveNoUserReferences(): void
    {
        self::assertSame([], Sql::userReferences('CREATE TABLE `src_posts` (`post_author` bigint); CREATE TABLE `src_comments` (`user_id` bigint);', 'src_'));
    }

    #[DataProvider('unsupportedUserRows')]
    public function testAmbiguousUserReferenceRowsFailClosed(string $insert): void
    {
        $sql = 'CREATE TABLE `src_posts` (`post_author` bigint, `content` text); CREATE TABLE `src_comments` (`user_id` bigint);' . $insert;
        $this->expectException(RuntimeException::class);
        Sql::userReferences($sql, 'src_');
    }

    public static function unsupportedUserRows(): array
    {
        return array_map(static fn ($sql) => [$sql], [
            "INSERT INTO `src_posts` VALUES (-1,'x');",
            "INSERT INTO `src_posts` VALUES (NULL,'x');",
            "INSERT INTO `src_posts` VALUES (1+2,'x');",
            "INSERT INTO `src_posts` VALUES (18446744073709551615,'x');",
            "INSERT INTO `src_posts` VALUES (1);",
            "INSERT INTO `src_posts` VALUES (1,'x'",
            "INSERT INTO `src_posts` VALUES (1,'x') ON DUPLICATE KEY UPDATE post_author=2;",
            "INSERT INTO `src_posts` (`content`) VALUES ('x');",
            "INSERT INTO `src_posts` (`post_author`, `post_author`) VALUES (1,2);",
            "INSERT INTO `src_posts` (`post_author`, `unknown`) VALUES (1,2);",
            "INSERT INTO `src_posts` (post_author, content) VALUES (1,'x');",
            "INSERT INTO `src_posts` SELECT 1, 'x';",
        ]);
    }

    public function testLargeContentDoesNotExhaustUserReferenceInspectionLimits(): void
    {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '10000');
        try {
            $sql = 'CREATE TABLE `src_posts` (`post_author` bigint, `content` text); CREATE TABLE `src_comments` (`user_id` bigint);';
            $sql .= "INSERT INTO `src_posts` VALUES (35,'" . str_repeat('comma, and ),( fake ', 100000) . "'),(4,'end');";
            self::assertSame([4, 35], Sql::userReferences($sql, 'src_'));
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }
    }

    #[DataProvider('jitModes')]
    public function testLargeValuesArePreservedWithLowRegexLimits(string $jit): void
    {
        $before = [];
        foreach (['pcre.jit' => $jit, 'pcre.recursion_limit' => '64', 'pcre.backtrack_limit' => '10000'] as $name => $value) {
            $before[$name] = ini_get($name);
            ini_set($name, $value);
        }
        try {
            $value = str_repeat("Grüße \\ ' \" # -- /* INSERT INTO `src_posts`; ", 25000);
            $quoted = "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
            $dump = static fn ($table) => "CREATE TABLE `$table` (`content` longtext);\nINSERT INTO `$table` VALUES ($quoted);\n/*!40000 ALTER TABLE `$table` ENABLE KEYS */;";
            $sql = $dump('src_posts');
            self::assertGreaterThan(1000000, strlen($quoted));
            self::assertSame(['src_posts'], Sql::tables($sql));
            self::assertSame(hash('sha256', $dump('longer_destination_42_posts')), hash('sha256', Sql::map($sql, ['src_posts' => 'longer_destination_42_posts'])));
            self::assertSame($sql, Sql::map($sql, ['src_posts' => 'src_posts']));
        } finally {
            foreach ($before as $name => $value) {
                ini_set($name, $value);
            }
        }
    }

    public static function jitModes(): array
    {
        return [['0'], ['1']];
    }

    public function testQuotedValuesIdentifiersAndCommentsKeepTheirBytes(): void
    {
        $sql = <<<'SQL'
-- CREATE TABLE `comment_table` ('not a value');
# INSERT INTO `comment_table` VALUES ('unfinished
/* quotes ' " ` are not tokens in this comment: CREATE TABLE `comment_table` */
CREATE TABLE `src_posts` (`quote' and " and # and -- and /* and ``tick` longtext);
INSERT INTO `src_posts` VALUES ('it''s CREATE TABLE `literal_table`'), ("double""quote and INSERT INTO `literal_table`"), ('backslash \\ and escaped \' quote'), ('last');
/*!40000 ALTER TABLE `src_posts` DISABLE KEYS */;
SQL;
        $expected = str_replace(['CREATE TABLE `src_posts`', 'INSERT INTO `src_posts`', 'ALTER TABLE `src_posts`'], ['CREATE TABLE `dst_123_posts`', 'INSERT INTO `dst_123_posts`', 'ALTER TABLE `dst_123_posts`'], $sql);
        self::assertSame(['src_posts'], Sql::tables($sql));
        self::assertSame($expected, Sql::map($sql, ['src_posts' => 'dst_123_posts']));
    }

    public function testLargeOrdinaryCommentsDoNotInventTables(): void
    {
        $comment = '/*' . str_repeat(" CREATE TABLE `foreign`; ' \" -- # ", 50000) . '*/';
        $sql = $comment . "\r\nCREATE TABLE `src_posts` (`id` int);\r\n-- CREATE TABLE `foreign`\r\n# INSERT INTO `foreign`";
        self::assertSame(['src_posts'], Sql::tables($sql));
        self::assertSame(str_replace('CREATE TABLE `src_posts`', 'CREATE TABLE `dst_posts`', $sql), Sql::map($sql, ['src_posts' => 'dst_posts']));
    }

    public function testArithmeticDoubleDashCannotHideForeignTableReference(): void
    {
        $this->expectExceptionMessage('outside the source site');
        Sql::map('SELECT 1--1; INSERT INTO `foreign` VALUES (1);', ['src_posts' => 'dst_posts']);
    }

    public function testExecutableCommentCannotHideForeignTableReference(): void
    {
        $this->expectExceptionMessage('outside the source site');
        Sql::map('/*!40000 ALTER TABLE `foreign` DISABLE KEYS */;', ['src_posts' => 'dst_posts']);
    }

    public function testForeignReferenceAfterLargeValueIsStillRejected(): void
    {
        $sql = "INSERT INTO `src_posts` VALUES ('" . str_repeat('x', 1000000) . "'); INSERT INTO `foreign` VALUES (1);";
        $this->expectExceptionMessage('outside the source site');
        Sql::map($sql, ['src_posts' => 'dst_posts']);
    }

    #[DataProvider('malformedSql')]
    public function testUnterminatedTokensFailWithoutExposingTheirContents(string $sql): void
    {
        try {
            Sql::tables($sql);
            self::fail('Malformed tokens must not be treated as a successful inspection.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('byte ', $error->getMessage());
            self::assertStringNotContainsString('synthetic-secret', $error->getMessage());
        }
    }

    public static function malformedSql(): array
    {
        return array_map(static fn ($sql) => [$sql], [
            "SELECT 'synthetic-secret", 'SELECT "synthetic-secret', 'CREATE TABLE `synthetic-secret',
            "SELECT 'synthetic-secret\\", '/* synthetic-secret', '/*!40000 synthetic-secret',
            "/*!40000 SELECT 'synthetic-secret */", '/*!40000 /* synthetic-secret */ */',
        ]);
    }

    #[DataProvider('inspectionMethods')]
    public function testRemainingRegexFailureIsReportedInsteadOfReturningAnEmptyPlan(string $method, string $message): void
    {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        try {
            ini_set('pcre.jit', '0');
            ini_set('pcre.backtrack_limit', '0');
            $this->expectExceptionMessage($message);
            if ($method === 'tables') {
                Sql::tables('CREATE TABLE `src_posts` (`id` int);');
            } else {
                Sql::map('CREATE TABLE `src_posts` (`id` int);', ['src_posts' => 'dst_posts']);
            }
        } finally {
            ini_set('pcre.jit', $jit);
            ini_set('pcre.backtrack_limit', $limit);
        }
    }

    public static function inspectionMethods(): array
    {
        return [
            ['tables', 'Could not inspect SQL table definitions'],
            ['map', 'Could not inspect SQL table references'],
        ];
    }
}
