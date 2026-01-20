<?php

namespace Tests\Unit;

use Atlas\Database\Drivers\MySqlTypeNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Atlas\Attributes\Column;
use Atlas\Attributes\Table;
use Atlas\Schema\Grammars\MySqlGrammar;
use Atlas\Schema\Parser\SchemaParser;

// Fixture Class for Testing
#[Table(name: 'users')]
class TestUserSchema {
    #[Column(type: 'integer', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Column(type: 'varchar', length: 255)]
    public string $email;

    public string $ignoredProperty = 'temp';
}

#[Table('test_private')]
class PrivatePropertySchema
{
    #[Column(type: 'varchar', length: 255)]
    private string $password;

    #[Column(type: 'varchar', length: 255)]
    protected string $apiKey;
}

class ParserTest extends TestCase
{
    #[Test]
    public function TestParsesPHPAttributesIntoDefinition(): void
    {
        $parser = new SchemaParser(new MySqlTypeNormalizer());
        $def = $parser->parse(TestUserSchema::class);

        $this->assertEquals('users', $def->tableName);
        $this->assertCount(2, $def->columns);

        $this->assertEquals('id', $def->columns['id']->name());
        $this->assertTrue($def->columns['id']->isPrimaryKey());
        $this->assertTrue($def->columns['id']->isAutoIncrement());

        $this->assertEquals('email', $def->columns['email']->name());
        $this->assertEquals('varchar(255)', $def->columns['email']->sqlType());
    }

    #[Test]
    public function TestGeneratesCreateTableSql(): void
    {
        $parser = new SchemaParser(new MySqlTypeNormalizer());
        $def = $parser->parse(TestUserSchema::class);

        $grammar = new MySqlGrammar();
        $sql = $grammar->createTable($def);

        $this->assertStringContainsString('CREATE TABLE `users`', $sql);
        $this->assertStringContainsString('`id` INT NOT NULL AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('PRIMARY KEY (`id`)', $sql);
    }

    #[Test]
    public function testSupportsPrivateProperties(): void
    {
        $parser = new SchemaParser(new MySqlTypeNormalizer());

        $def = $parser->parse(PrivatePropertySchema::class);

        $this->assertCount(2, $def->columns);
        $this->assertEquals('password', $def->columns['password']->name);
        $this->assertEquals('varchar(255)', $def->columns['password']->sqlType);
    }

}