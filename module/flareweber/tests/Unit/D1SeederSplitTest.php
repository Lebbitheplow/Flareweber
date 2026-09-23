<?php

namespace FlareWeber\Tests\Unit;

use FlareWeber\Deploy\D1Seeder;
use PHPUnit\Framework\TestCase;

class D1SeederSplitTest extends TestCase
{
    public function testSplitsOnTopLevelSemicolonsOnly(): void
    {
        $sql = "CREATE TABLE a (id INTEGER);\nINSERT INTO a VALUES (1); INSERT INTO a VALUES (2)";

        $this->assertSame([
            'CREATE TABLE a (id INTEGER)',
            'INSERT INTO a VALUES (1)',
            'INSERT INTO a VALUES (2)',
        ], D1Seeder::splitStatements($sql));
    }

    public function testRespectsSqliteQuoteEscapingAndSemicolonsInsideStrings(): void
    {
        $sql = "INSERT INTO t VALUES ('it''s; fine');INSERT INTO t VALUES ('a;b');";

        $this->assertSame([
            "INSERT INTO t VALUES ('it''s; fine')",
            "INSERT INTO t VALUES ('a;b')",
        ], D1Seeder::splitStatements($sql));
    }

    public function testDoesNotStripDashesInsideStrings(): void
    {
        $sql = "-- header comment\nINSERT INTO t VALUES ('x -- not a comment; still string');\n-- trailing\n";

        $this->assertSame(
            ["INSERT INTO t VALUES ('x -- not a comment; still string')"],
            D1Seeder::splitStatements($sql)
        );
    }

    public function testStripsLineAndBlockCommentsOutsideStrings(): void
    {
        $sql = "SELECT 1; -- one\n/* multi\nline; comment */ SELECT 2;";

        $this->assertSame(['SELECT 1', 'SELECT 2'], D1Seeder::splitStatements($sql));
    }

    public function testDoubleQuotedIdentifiersAreOpaque(): void
    {
        $sql = 'CREATE TABLE "weird;name" (id INTEGER); SELECT 1';

        $this->assertSame(['CREATE TABLE "weird;name" (id INTEGER)', 'SELECT 1'], D1Seeder::splitStatements($sql));
    }

    public function testEmptyAndWhitespaceOnlyInput(): void
    {
        $this->assertSame([], D1Seeder::splitStatements(''));
        $this->assertSame([], D1Seeder::splitStatements(";;\n  ;"));
    }
}
