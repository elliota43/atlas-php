<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SchemaOps\Schema\Definition\ColumnDefinition;
use SchemaOps\Schema\Definition\TableDefinition;
use SchemaOps\State\LockfileManager;

class LockfileManagerTest extends TestCase
{
    private string $tempDir;
    private LockfileManager $manager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/schemaops_test_' . uniqid();
        mkdir($this->tempDir);
        $this->manager = new LockfileManager($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDir . '/schema.lock')) {
            unlink($this->tempDir . '/schema.lock');
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function TestSavesAndLoadsLockfile(): void
    {
        $table = new TableDefinition('users');
        $table->addColumn(new ColumnDefinition(
            name: 'id',
            sqlType: 'integer',
            isNullable: false,
            isAutoIncrement: true,
            isPrimaryKey: true,
            defaultValue: null
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'email',
            sqlType: 'varchar(255)',
            isNullable: false,
            isAutoIncrement: false,
            isPrimaryKey: false,
            defaultValue: null
        ));

        $definitions = ['users' => $table];

        $this->manager->save($definitions);
        $loaded = $this->manager->load();

        $this->assertArrayHasKey('users', $loaded);
        $this->assertEquals('users', $loaded['users']->tableName);
        $this->assertCount(2, $loaded['users']->columns);

        $this->assertEquals('id', $loaded['users']->columns['id']->name());
        $this->assertTrue($loaded['users']->columns['id']->isPrimaryKey());
        $this->assertTrue($loaded['users']->columns['id']->isAutoIncrement());
        
        $this->assertEquals('email', $loaded['users']->columns['email']->name());
        $this->assertEquals('varchar(255)', $loaded['users']->columns['email']->sqlType());
    }

    #[Test]
    public function TestReturnsEmptyArrayWhenLockFileDoesNotExist(): void
    {
        $result = $this->manager->load();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[Test]
    public function TestPreservesColumnProperties(): void
    {
        $table = new TableDefinition('products');
        $table->addColumn(new ColumnDefinition(
            name: 'price',
            sqlType: 'decimal(10,2)',
            isNullable: true,
            isAutoIncrement: false,
            isPrimaryKey: false,
            defaultValue: '0.00'
        ));

        $this->manager->save(['products' => $table]);
        $loaded = $this->manager->load();

        $priceCol = $loaded['products']->columns['price'];
        $this->assertTrue($priceCol->isNullable());
        $this->assertFalse($priceCol->isAutoIncrement());
        $this->assertEquals('0.00', $priceCol->defaultValue());
    }

    #[Test]
    public function TestHandlesMultipleTables(): void
    {
        $users = new TableDefinition('users');
        $users->addColumn(new ColumnDefinition('id', 'integer', false, true, true, null));

        $posts = new TableDefinition('posts');
        $posts->addColumn(new ColumnDefinition('id', 'integer', false, true, true, null));
        $posts->addColumn(new ColumnDefinition('title', 'varchar(200)', false, false, false, null));

        $definitions = [
            'users' => $users,
            'posts' => $posts
        ];

        $this->manager->save($definitions);
        $loaded = $this->manager->load();

        $this->assertCount(2, $loaded);
        $this->assertArrayHasKey('users', $loaded);
        $this->assertArrayHasKey('posts', $loaded);
        $this->assertCount(2, $loaded['posts']->columns);
    }
}