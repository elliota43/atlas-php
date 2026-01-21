<?php

declare(strict_types=1);

namespace Tests\Console\Commands;

use Atlas\Connection\ConnectionManager;
use Atlas\Console\Commands\MigrateCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class MigrateCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private ConnectionManager $connectionManager;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->connectionManager = $this->createConnectionManager();
        $this->pdo = $this->connectionManager->connection('default');

        $this->resetDatabase();
        $this->setupCommandTester();
    }

    protected function tearDown(): void
    {
        $this->resetDatabase();
    }

    protected function setupCommandTester(): void
    {
        $command = new MigrateCommand($this->connectionManager);
        $application = new Application();
        $application->add($command);

        $this->commandTester = new CommandTester($command);
    }

    // ==========================================
    // YAML Configuration Tests
    // ==========================================

    #[Test]
    public function it_creates_table_from_yaml_schema(): void
    {
        $yamlPath = $this->createYamlFixture('users', [
            'columns' => [
                'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true, 'primary' => true],
                'email' => ['type' => 'varchar', 'length' => 255, 'nullable' => false],
                'name' => ['type' => 'varchar', 'length' => 255, 'nullable' => false],
            ],
        ]);

        $this->executeCommand(['--yaml-path' => $yamlPath, '--force' => true]);

        $this->assertTableExists('users');
        $this->assertColumnExists('users', 'id');
        $this->assertColumnExists('users', 'email');
        $this->assertColumnExists('users', 'name');
        $this->assertCommandSucceeded();
    }

    #[Test]
    public function it_adds_column_from_yaml_schema(): void
    {
        $this->createTable('users', [
            'id' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'email' => 'VARCHAR(255) NOT NULL',
        ]);

        $yamlPath = $this->createYamlFixture('users', [
            'columns' => [
                'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true, 'primary' => true],
                'email' => ['type' => 'varchar', 'length' => 255, 'nullable' => false],
                'name' => ['type' => 'varchar', 'length' => 255, 'nullable' => false],
            ],
        ]);

        $this->executeCommand(['--yaml-path' => $yamlPath, '--force' => true]);

        $this->assertColumnExists('users', 'name');
        $this->assertCommandSucceeded();
    }

    #[Test]
    public function it_modifies_column_from_yaml_schema(): void
    {
        $this->createTable('users', [
            'id' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'email' => 'VARCHAR(100) NOT NULL',
        ]);

        $yamlPath = $this->createYamlFixture('users', [
            'columns' => [
                'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true, 'primary' => true],
                'email' => ['type' => 'varchar', 'length' => 255, 'nullable' => false],
            ],
        ]);

        $this->executeCommand(['--yaml-path' => $yamlPath, '--force' => true]);

        $this->assertColumnType('users', 'email', 'varchar(255)');
        $this->assertCommandSucceeded();
    }

    #[Test]
    public function it_creates_index_from_yaml_schema(): void
    {
        $yamlPath = $this->createYamlFixture('users', [
            'columns' => [
                'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true, 'primary' => true],
                'email' => ['type' => 'varchar', 'length' => 255, 'nullable' => false, 'unique' => true],
            ],
        ]);

        $this->executeCommand(['--yaml-path' => $yamlPath, '--force' => true]);

        $this->assertIndexExists('users', 'email');
        $this->assertCommandSucceeded();
    }

    #[Test]
    public function it_creates_foreign_key_from_yaml_schema(): void
    {
        // First, create the users table that will be referenced
        $this->createTable('users', [
            'id' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
        ]);

        $yamlPath = $this->getYamlFixturePath();

        // Create users table YAML (to prevent it from being dropped)
        $this->createYamlFixtureFile($yamlPath, 'users.schema.yaml', [
            'tables' => [
                'users' => [
                    'columns' => [
                        'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true, 'primary' => true],
                    ],
                ],
            ],
        ]);

        // Create posts table YAML with FK to users
        $this->createYamlFixtureFile($yamlPath, 'posts.schema.yaml', [
            'tables' => [
                'posts' => [
                    'columns' => [
                        'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true, 'primary' => true],
                        'user_id' => [
                            'type' => 'bigint',
                            'unsigned' => true,
                            'nullable' => false,
                            'foreign_key' => [
                                'table' => 'users',
                                'column' => 'id',
                                'on_delete' => 'cascade',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->executeCommand(['--yaml-path' => $yamlPath, '--force' => true]);

        $this->assertTableExists('users');
        $this->assertTableExists('posts');
        $this->assertForeignKeyExists('posts', 'user_id');
        $this->assertCommandSucceeded();
    }

    #[Test]
    public function it_discovers_multiple_yaml_files(): void
    {
        $yamlPath = $this->getYamlFixturePath();

        $this->createYamlFixtureFile($yamlPath, 'users.schema.yaml', [
            'tables' => [
                'users' => [
                    'columns' => [
                        'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true, 'primary' => true],
                        'email' => ['type' => 'varchar', 'length' => 255],
                    ],
                ],
            ],
        ]);

        $this->createYamlFixtureFile($yamlPath, 'posts.schema.yaml', [
            'tables' => [
                'posts' => [
                    'columns' => [
                        'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true, 'primary' => true],
                        'title' => ['type' => 'varchar', 'length' => 255],
                    ],
                ],
            ],
        ]);

        $this->executeCommand(['--yaml-path' => $yamlPath, '--force' => true]);

        $this->assertTableExists('users');
        $this->assertTableExists('posts');
        $this->assertCommandSucceeded();
    }

    // ==========================================
    // PHP Attribute Configuration Tests
    // ==========================================

    #[Test]
    public function it_creates_table_from_php_attributes(): void
    {
        $phpPath = $this->getPhpFixturePath();

        $this->executeCommand([
            '--path' => $phpPath,
            '--force' => true,
        ]);

        $this->assertTableExists('users');
        $this->assertColumnExists('users', 'id');
        $this->assertColumnExists('users', 'email');
        $this->assertCommandSucceeded();
    }

    #[Test]
    public function it_adds_column_from_php_attributes(): void
    {
        $this->createTable('users', [
            'id' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
        ]);

        $phpPath = $this->getPhpFixturePath();

        $this->executeCommand([
            '--path' => $phpPath,
            '--force' => true,
        ]);

        $this->assertColumnExists('users', 'email');
        $this->assertColumnExists('users', 'name');
        $this->assertCommandSucceeded();
    }

    #[Test]
    public function it_modifies_column_from_php_attributes(): void
    {
        $this->createTable('users', [
            'id' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'email' => 'VARCHAR(100) NOT NULL',
            'name' => 'VARCHAR(100) NOT NULL',
            'created_at' => 'TIMESTAMP NULL',
            'updated_at' => 'TIMESTAMP NULL',
        ]);

        $phpPath = $this->getPhpFixturePath();

        $this->executeCommand([
            '--path' => $phpPath,
            '--force' => true,
        ]);

        $this->assertColumnType('users', 'email', 'varchar(255)');
        $this->assertCommandSucceeded();
    }

    // ==========================================
    // Combined YAML + PHP Tests
    // ==========================================

    #[Test]
    public function it_combines_yaml_and_php_schemas(): void
    {
        $yamlPath = $this->createYamlFixture('posts', [
            'columns' => [
                'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true, 'primary' => true],
                'title' => ['type' => 'varchar', 'length' => 255],
            ],
        ]);

        $phpPath = $this->getPhpFixturePath();

        $this->executeCommand([
            '--path' => $phpPath,
            '--yaml-path' => $yamlPath,
            '--force' => true,
        ]);

        $this->assertTableExists('users');
        $this->assertTableExists('posts');
        $this->assertCommandSucceeded();
    }

    // ==========================================
    // Dry Run Tests
    // ==========================================

    #[Test]
    public function it_shows_sql_without_executing_in_dry_run_mode(): void
    {
        $yamlPath = $this->createYamlFixture('users', [
            'columns' => [
                'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true, 'primary' => true],
                'email' => ['type' => 'varchar', 'length' => 255],
            ],
        ]);

        $this->executeCommand(['--yaml-path' => $yamlPath, '--dry-run' => true]);

        $this->assertStringContainsString('CREATE TABLE', $this->getOutput());
        $this->assertTableDoesNotExist('users');
        $this->assertCommandSucceeded();
    }

    #[Test]
    public function it_displays_no_changes_message_when_schema_matches(): void
    {
        $this->createTable('users', [
            'id' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'email' => 'VARCHAR(255) NOT NULL',
        ]);

        $yamlPath = $this->createYamlFixture('users', [
            'columns' => [
                'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true, 'primary' => true],
                'email' => ['type' => 'varchar', 'length' => 255, 'nullable' => false],
            ],
        ]);

        $this->executeCommand(['--yaml-path' => $yamlPath, '--force' => true]);

        $this->assertStringContainsString('up to date', $this->getOutput());
        $this->assertCommandSucceeded();
    }

    // ==========================================
    // Confirmation Tests
    // ==========================================

    #[Test]
    public function it_requires_confirmation_for_destructive_changes(): void
    {
        // Create a table that will be DROPPED (destructive)
        $this->createTable('old_table', [
            'id' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
        ]);

        // Define schema WITHOUT old_table - causing it to be marked for DROP
        $yamlPath = $this->createYamlFixture('users', [
            'columns' => [
                'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true, 'primary' => true],
            ],
        ]);

        // Input 'n' to skip the destructive change
        $this->commandTester->setInputs(['n']);
        $this->executeCommand(['--yaml-path' => $yamlPath]);

        // old_table should still exist because user said 'no' to drop
        $this->assertTableExists('old_table');
    }

    #[Test]
    public function it_executes_when_user_confirms(): void
    {
        $yamlPath = $this->createYamlFixture('users', [
            'columns' => [
                'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true, 'primary' => true],
            ],
        ]);

        $this->commandTester->setInputs(['yes']);
        $this->executeCommand(['--yaml-path' => $yamlPath]);

        $this->assertTableExists('users');
        $this->assertCommandSucceeded();
    }

    // ==========================================
    // Error Handling Tests
    // ==========================================

    #[Test]
    public function it_displays_warning_when_no_schemas_found(): void
    {
        $emptyPath = $this->createTemporaryDirectory();

        $this->executeCommand(['--yaml-path' => $emptyPath]);

        $this->assertStringContainsString('No schemas found', $this->getOutput());

        $this->removeDirectory($emptyPath);
    }

    #[Test]
    public function it_handles_invalid_yaml_gracefully(): void
    {
        $yamlPath = $this->getYamlFixturePath();
        file_put_contents("{$yamlPath}/invalid.schema.yaml", "invalid: yaml: content: [");

        $this->executeCommand(['--yaml-path' => $yamlPath, '--force' => true]);

        $this->assertCommandFailed();
    }

    #[Test]
    public function it_reports_duplicate_table_definitions(): void
    {
        $yamlPath = $this->getYamlFixturePath();

        $this->createYamlFixtureFile($yamlPath, 'users1.schema.yaml', [
            'tables' => [
                'users' => [
                    'columns' => [
                        'id' => ['type' => 'bigint', 'primary' => true],
                    ],
                ],
            ],
        ]);

        $this->createYamlFixtureFile($yamlPath, 'users2.schema.yaml', [
            'tables' => [
                'users' => [
                    'columns' => [
                        'id' => ['type' => 'bigint', 'primary' => true],
                    ],
                ],
            ],
        ]);

        $this->executeCommand(['--yaml-path' => $yamlPath, '--force' => true]);

        $this->assertStringContainsString('Duplicate table', $this->getOutput());
        $this->assertCommandFailed();
    }

    // ==========================================
    // Connection Tests
    // ==========================================

    #[Test]
    public function it_uses_specified_connection(): void
    {
        $yamlPath = $this->createYamlFixture('users', [
            'columns' => [
                'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true, 'primary' => true],
            ],
        ]);

        $this->executeCommand([
            '--yaml-path' => $yamlPath,
            '--connection' => 'default',
            '--force' => true,
        ]);

        $this->assertTableExists('users');
        $this->assertCommandSucceeded();
    }

    // ==========================================
    // Helper Methods - Command Execution
    // ==========================================

    protected function executeCommand(array $options): void
    {
        $this->commandTester->execute($options);
    }

    protected function getOutput(): string
    {
        return $this->commandTester->getDisplay();
    }

    // ==========================================
    // Helper Methods - Setup
    // ==========================================

    protected function createConnectionManager(): ConnectionManager
    {
        return new ConnectionManager([
            'default' => [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: 3306,
                'database' => getenv('DB_DATABASE') ?: 'test_schema',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: 'root',
            ],
        ]);
    }

    protected function resetDatabase(): void
    {
        $this->disableForeignKeyChecks();
        $this->dropAllTables();
        $this->enableForeignKeyChecks();
        $this->cleanupYamlFixtures();
    }

    protected function disableForeignKeyChecks(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    }

    protected function enableForeignKeyChecks(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function dropAllTables(): void
    {
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    // ==========================================
    // Helper Methods - Database Operations
    // ==========================================

    protected function createTable(string $name, array $columns): void
    {
        $columnDefinitions = $this->buildColumnDefinitions($columns);
        $sql = "CREATE TABLE `{$name}` ({$columnDefinitions})";

        $this->pdo->exec($sql);
    }

    protected function buildColumnDefinitions(array $columns): string
    {
        $definitions = [];

        foreach ($columns as $name => $definition) {
            $definitions[] = "`{$name}` {$definition}";
        }

        return implode(', ', $definitions);
    }

    // ==========================================
    // Helper Methods - YAML Fixtures
    // ==========================================

    protected function getYamlFixturePath(): string
    {
        return $this->createTemporaryDirectory('atlas_yaml_fixtures_');
    }

    protected function getPhpFixturePath(): string
    {
        return __DIR__ . '/../../Fixtures/Schemas';
    }

    protected function createTemporaryDirectory(string $prefix = 'atlas_test_'): string
    {
        $path = sys_get_temp_dir() . "/{$prefix}" . uniqid();
        mkdir($path, 0777, true);

        return $path;
    }

    protected function createYamlFixture(string $tableName, array $tableDefinition): string
    {
        $path = $this->getYamlFixturePath();

        $yaml = [
            'tables' => [
                $tableName => $tableDefinition,
            ],
        ];

        $this->createYamlFixtureFile($path, "{$tableName}.schema.yaml", $yaml);

        return $path;
    }

    protected function createYamlFixtureFile(string $path, string $filename, array $content): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $yaml = \Symfony\Component\Yaml\Yaml::dump($content, 10, 2);

        file_put_contents("{$path}/{$filename}", $yaml);
    }

    protected function cleanupYamlFixtures(): void
    {
        $directories = $this->getYamlFixtureDirectories();

        foreach ($directories as $directory) {
            $this->removeDirectory($directory);
        }
    }

    protected function getYamlFixtureDirectories(): array
    {
        $pattern = sys_get_temp_dir() . '/atlas_yaml_fixtures_*';

        return glob($pattern) ?: [];
    }

    protected function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $this->removeDirectoryContents($path);
        rmdir($path);
    }

    protected function removeDirectoryContents(string $path): void
    {
        $files = $this->getDirectoryFiles($path);

        foreach ($files as $file) {
            $filePath = "{$path}/{$file}";

            is_dir($filePath)
                ? $this->removeDirectory($filePath)
                : unlink($filePath);
        }
    }

    protected function getDirectoryFiles(string $path): array
    {
        return array_diff(scandir($path), ['.', '..']);
    }

    // ==========================================
    // Assertion Helpers
    // ==========================================

    protected function assertCommandSucceeded(): void
    {
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    protected function assertCommandFailed(): void
    {
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    protected function assertTableExists(string $table): void
    {
        $result = $this->pdo->query("SHOW TABLES LIKE '{$table}'")->fetch();

        $this->assertNotFalse($result, "Table '{$table}' should exist");
    }

    protected function assertTableDoesNotExist(string $table): void
    {
        $result = $this->pdo->query("SHOW TABLES LIKE '{$table}'")->fetch();

        $this->assertFalse($result, "Table '{$table}' should not exist");
    }

    protected function assertColumnExists(string $table, string $column): void
    {
        $result = $this->pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")->fetch();

        $this->assertNotFalse($result, "Column '{$column}' should exist in table '{$table}'");
    }

    protected function assertColumnType(string $table, string $column, string $expectedType): void
    {
        $result = $this->pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")->fetch();

        $this->assertNotFalse($result, "Column '{$column}' should exist in table '{$table}'");
        $this->assertStringContainsString(
            strtolower($expectedType),
            strtolower($result['Type']),
            "Column '{$column}' should have type '{$expectedType}'"
        );
    }

    protected function assertIndexExists(string $table, string $column): void
    {
        $result = $this->pdo->query("SHOW INDEX FROM `{$table}` WHERE Column_name = '{$column}'")->fetch();

        $this->assertNotFalse($result, "Index on column '{$column}' should exist in table '{$table}'");
    }

    protected function assertForeignKeyExists(string $table, string $column): void
    {
        $sql = "
            SELECT * FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$table}'
            AND COLUMN_NAME = '{$column}'
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ";

        $result = $this->pdo->query($sql)->fetch();

        $this->assertNotFalse($result, "Foreign key on column '{$column}' should exist in table '{$table}'");
    }
}
