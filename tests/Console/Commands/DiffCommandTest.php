<?php

declare(strict_types=1);

namespace Tests\Console\Commands;

use Atlas\Connection\ConnectionManager;
use Atlas\Console\Commands\DiffCommand;
use Atlas\Schema\Definition\ColumnDefinition;
use Atlas\Schema\Definition\TableDefinition;
use Atlas\Schema\Loader\SchemaLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class DiffCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private ConnectionManager $connectionManager;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->connectionManager = $this->createConnectionManager();
        $this->pdo = $this->connectionManager->connection('default');

        $this->resetDatabase();

        $command = new DiffCommand($this->connectionManager);

        $application = new Application();
        $application->add($command);

        $this->commandTester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        $this->resetDatabase();
    }

    protected function resetDatabase(): void
    {
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    #[Test]
    public function it_displays_warning_when_no_schemas_found(): void
    {
        $this->commandTester->execute([
            '--path' => '/path/that/does/not/exist',
        ]);

        $output = $this->commandTester->getDisplay();

        $this->assertStringContainsString('No schema definitions found', $output);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    #[Test]
    public function it_loads_schemas_from_specified_path(): void
    {
        $testPath = $this->createTestSchemaDirectory();

        $this->commandTester->execute([
            '--path' => $testPath,
            '--namespace' => 'Tests\\Fixtures',
        ]);

        $output = $this->commandTester->getDisplay();

        $this->assertStringContainsString('Found', $output);
        $this->assertStringContainsString('table definition', $output);
    }

    #[Test]
    public function it_loads_schemas_from_yaml_path(): void
    {
        $testPath = $this->createTestYamlDirectory();

        $this->commandTester->execute([
            '--yaml-path' => $testPath,
        ]);

        $output = $this->commandTester->getDisplay();

        $this->assertStringContainsString('Found', $output);
        $this->assertStringContainsString('table definition', $output);
    }

    #[Test]
    public function it_combines_php_and_yaml_schemas(): void
    {
        $phpPath = $this->createTestSchemaDirectory();
        $yamlPath = $this->createTestYamlDirectory();

        $this->commandTester->execute([
            '--path' => $phpPath,
            '--yaml-path' => $yamlPath,
            '--namespace' => 'Tests\\Fixtures',
        ]);

        $output = $this->commandTester->getDisplay();

        $this->assertStringContainsString('Found 2 table definition(s)', $output);
    }

    #[Test]
    public function it_auto_discovers_from_project_root_when_no_paths_specified(): void
    {
        // This assumes the test is run from project root
        $this->commandTester->execute([]);

        $output = $this->commandTester->getDisplay();

        $this->assertStringContainsString('Scanning project root:', $output);
    }

    #[Test]
    public function it_displays_success_when_schema_is_up_to_date(): void
    {
        $testPath = $this->createTestSchemaDirectory();
        $this->seedDatabaseWithMatchingSchema();

        $this->commandTester->execute([
            '--path' => $testPath,
            '--namespace' => 'Tests\\Fixtures',
        ]);

        $output = $this->commandTester->getDisplay();

        $this->assertStringContainsString('Database schema is up to date!', $output);
    }

    #[Test]
    public function it_generates_migration_sql_when_changes_detected(): void
    {
        $testPath = $this->createTestSchemaDirectory();
        $this->seedDatabaseWithOutdatedSchema();

        $this->commandTester->execute([
            '--path' => $testPath,
            '--namespace' => 'Tests\\Fixtures',
        ]);

        $output = $this->commandTester->getDisplay();

        $this->assertStringContainsString('Migration SQL', $output);
        $this->assertStringContainsString('ALTER TABLE', $output);
    }

    #[Test]
    public function it_saves_sql_to_file_when_output_option_provided(): void
    {
        $testPath = $this->createTestSchemaDirectory();
        $outputFile = sys_get_temp_dir() . '/migration.sql';

        $this->seedDatabaseWithOutdatedSchema();

        $this->commandTester->execute([
            '--path' => $testPath,
            '--namespace' => 'Tests\\Fixtures',
            '--output' => $outputFile,
        ]);

        $this->assertFileExists($outputFile);
        $this->assertStringContainsString('ALTER TABLE', file_get_contents($outputFile));

        unlink($outputFile);
    }

    #[Test]
    public function it_uses_specified_database_connection(): void
    {
        $testPath = $this->createTestSchemaDirectory();

        $this->commandTester->execute([
            '--path' => $testPath,
            '--namespace' => 'Tests\\Fixtures',
            '--connection' => 'testing',
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    #[Test]
    public function it_handles_schema_exceptions_gracefully(): void
    {
        $this->commandTester->execute([
            '--path' => '/invalid/path',
            '--namespace' => 'Invalid\\Namespace',
        ]);

        $output = $this->commandTester->getDisplay();

        $this->assertStringContainsString('No schema definitions found', $output);
    }

    #[Test]
    public function it_displays_count_of_generated_sql_statements(): void
    {
        $testPath = $this->createTestSchemaDirectory();
        $this->seedDatabaseWithOutdatedSchema();

        $this->commandTester->execute([
            '--path' => $testPath,
            '--namespace' => 'Tests\\Fixtures',
        ]);

        $output = $this->commandTester->getDisplay();

        $this->assertStringContainsString('Generated', $output);
        $this->assertStringContainsString('SQL statement(s)', $output);
    }

    protected function createConnectionManager(): ConnectionManager
    {
        $config = [
            'default' => [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'database' => getenv('DB_DATABASE') ?: 'atlas_test',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
            ],
            'testing' => [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'database' => getenv('DB_DATABASE') ?: 'atlas_test',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
            ],
        ];

        return new ConnectionManager($config);
    }

    protected function createTestSchemaDirectory(): string
    {
        // Return path to test fixtures with schema classes
        return __DIR__ . '/../../Fixtures/Schemas';
    }

    protected function createTestYamlDirectory(): string
    {
        // Return path to test fixtures with YAML files
        return __DIR__ . '/../../Fixtures/Yaml';
    }

    protected function seedDatabaseWithMatchingSchema(): void
    {
        // Create tables that match the test schema definitions
        $pdo = $this->connectionManager->connection('default');

        $pdo->exec('DROP TABLE IF EXISTS users');
        $pdo->exec('
            CREATE TABLE users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        ');
    }

    protected function seedDatabaseWithOutdatedSchema(): void
    {
        // Create tables that don't match the test schema definitions
        $pdo = $this->connectionManager->connection('default');

        $pdo->exec('DROP TABLE IF EXISTS users');
        $pdo->exec('
            CREATE TABLE users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(100) NOT NULL,
                created_at TIMESTAMP NULL
            )
        ');
    }
}