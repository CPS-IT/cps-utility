<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\Tests\Unit\Fixture;

use Cpsit\CpsUtility\Fixture\SqlStatementSplitter;
use PHPUnit\Framework\TestCase;

final class SqlStatementSplitterTest extends TestCase
{
    private SqlStatementSplitter $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new SqlStatementSplitter();
    }

    public function testEmptyInputProducesNoStatements(): void
    {
        self::assertSame([], $this->subject->split(''));
    }

    public function testCommentOnlyInputProducesNoStatements(): void
    {
        self::assertSame([], $this->subject->split("-- just a comment\n"));
    }

    public function testWhitespaceOnlyInputProducesNoStatements(): void
    {
        self::assertSame([], $this->subject->split("   \n\t  \n"));
    }

    public function testSingleStatementIsReturnedTrimmed(): void
    {
        self::assertSame(
            ['SELECT 1'],
            $this->subject->split('  SELECT 1;  ')
        );
    }

    public function testMultipleStatementsInOneFile(): void
    {
        self::assertSame(
            ['SELECT 1', 'SELECT 2', 'SELECT 3'],
            $this->subject->split('SELECT 1; SELECT 2; SELECT 3;')
        );
    }

    public function testTrailingStatementWithoutSemicolonIsIncluded(): void
    {
        self::assertSame(
            ['SELECT 1', 'SELECT 2'],
            $this->subject->split('SELECT 1; SELECT 2')
        );
    }

    public function testDoubleDashLineCommentIsStripped(): void
    {
        self::assertSame(
            ['SELECT 1', 'SELECT 2'],
            $this->subject->split("SELECT 1; -- a comment with a ; inside it\nSELECT 2;")
        );
    }

    public function testHashLineCommentIsStripped(): void
    {
        self::assertSame(
            ['SELECT 1', 'SELECT 2'],
            $this->subject->split("# a comment with a ; inside it\nSELECT 1;\nSELECT 2;")
        );
    }

    public function testBlockCommentIsStripped(): void
    {
        self::assertSame(
            ['SELECT 1'],
            $this->subject->split('/* a block comment with a ; inside it */ SELECT 1;')
        );
    }

    public function testMultiLineBlockCommentIsStripped(): void
    {
        $sql = "/*\n * multi-line comment\n * with a ; inside it\n */\nSELECT 1;";

        self::assertSame(['SELECT 1'], $this->subject->split($sql));
    }

    public function testSemicolonInsideSingleQuotedValueDoesNotSplit(): void
    {
        self::assertSame(
            ["INSERT INTO t (a) VALUES ('a;b')"],
            $this->subject->split("INSERT INTO t (a) VALUES ('a;b');")
        );
    }

    public function testDoubledSingleQuoteEscapeInsideValue(): void
    {
        self::assertSame(
            ["INSERT INTO t (a) VALUES ('it''s a test')"],
            $this->subject->split("INSERT INTO t (a) VALUES ('it''s a test');")
        );
    }

    public function testBackslashEscapedSingleQuoteInsideValue(): void
    {
        self::assertSame(
            ["INSERT INTO t (a) VALUES ('it\\'s a test')"],
            $this->subject->split("INSERT INTO t (a) VALUES ('it\\'s a test');")
        );
    }

    public function testBacktickIdentifierContainingSemicolonDoesNotSplit(): void
    {
        self::assertSame(
            ['SELECT `col;name` FROM t'],
            $this->subject->split('SELECT `col;name` FROM t;')
        );
    }

    public function testDoubleQuotedIdentifierContainingSemicolonDoesNotSplit(): void
    {
        self::assertSame(
            ['SELECT "col;name" FROM t'],
            $this->subject->split('SELECT "col;name" FROM t;')
        );
    }

    public function testDoubleDashInsideSingleQuotedValueIsNotTreatedAsComment(): void
    {
        self::assertSame(
            ["INSERT INTO t (a) VALUES ('a--b')"],
            $this->subject->split("INSERT INTO t (a) VALUES ('a--b');")
        );
    }
}
