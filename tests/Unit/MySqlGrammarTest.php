<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Atlas\Schema\Grammars\MySqlGrammar;
use Atlas\Schema\Definition\ColumnDefinition;
use Atlas\Schema\Definition\TableDefinition;

class MySqlGrammarTest extends TestCase
{
    private MySqlGrammar $grammar;

    protected function setUp(): void
    {
        $this->grammar = new MySqlGrammar();
    }

    #[Test]
    public function testNullDefaultNotQuoted(): void
    {
        $table = new TableDefinition('test');
        $table->addColumn(new ColumnDefinition(
            name: 'nickname',
            sqlType: 'varchar(255)',
            isNullable: true,
            isPrimaryKey: false,
            isAutoIncrement: false,
            defaultValue: 'NULL'  // User writes string 'NULL'
        ));

        $sql = $this->grammar->createTable($table);

        // Should generate: DEFAULT NULL (no quotes)
        $this->assertStringContainsString('DEFAULT NULL', $sql);
        $this->assertStringNotContainsString("'NULL'", $sql);
    }

    #[Test]
    public function testFunctionCallNotQuoted(): void
    {
        $table = new TableDefinition('test');
        $table->addColumn(new ColumnDefinition(
            name: 'id',
            sqlType: 'char(36)',
            isNullable: false,
            isPrimaryKey: true,
            isAutoIncrement: false,
            defaultValue: 'UUID()'  // User writes string 'UUID()'
        ));

        $sql = $this->grammar->createTable($table);

        // Should generate: DEFAULT UUID() (no quotes)
        $this->assertStringContainsString('DEFAULT UUID()', $sql);
        $this->assertStringNotContainsString("'UUID()'", $sql);
    }

    #[Test]
    public function testStringLiteralIsQuoted(): void
    {
        $table = new TableDefinition('test');
        $table->addColumn(new ColumnDefinition(
            name: 'status',
            sqlType: 'varchar(50)',
            isNullable: false,
            isPrimaryKey: false,
            isAutoIncrement: false,
            defaultValue: 'active'  // Regular string
        ));

        $sql = $this->grammar->createTable($table);

        // Should generate: DEFAULT 'active' (with quotes)
        $this->assertStringContainsString("DEFAULT 'active'", $sql);
    }
}