<?php

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SchemaOps\Database\Drivers\MySqlDriver;

class DatabaseIntrospectionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')
        );

        $this->pdo = new PDO($dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    }

    #[Test]
    public function TestCanIntrospectLegacyUsersTable(): void
    {
        $driver = new MySqlDriver($this->pdo);
        $schema = $driver->getCurrentSchema();

        $this->assertArrayHasKey('legacy_users', $schema);

        $table = $schema['legacy_users'];
        $this->assertEquals('legacy_users', $table->tableName);

        // Assert specific columns exist from your init script
        $this->assertArrayHasKey('email', $table->columns);
        $this->assertTrue($table->columns['id']->isPrimaryKey());
        $this->assertTrue($table->columns['id']->isAutoIncrement());
    }
}