<?php

namespace SchemaOps\Console\Commands;

use PDO;
use PDOException;
use SchemaOps\Changes\TableChanges;
use SchemaOps\Comparison\TableComparator;
use SchemaOps\Database\Drivers\MySqlDriver;
use SchemaOps\Schema\Definition\TableDefinition;
use SchemaOps\Schema\Discovery\ClassFinder;
use SchemaOps\Schema\Parser\SchemaParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DiffCommand extends Command
{

    protected function configure(): void
    {
        $this
            ->setName('schema:diff')
            ->setDescription('Show differences between your attribute-based schema and database.')
            ->addArgument('path', InputArgument::OPTIONAL, 'Path to scan for schema classes', 'tests/Integration')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Database host', getenv('DB_HOST') ?: '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Database port', getenv('DB_PORT') ?: '3306')
            ->addOption('database', 'd', InputOption::VALUE_REQUIRED, 'Database name', getenv('DB_DATABASE') ?: 'test_schema')
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'Database username', getenv('DB_USERNAME') ?: 'root')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Database password', getenv('DB_PASSWORD') ?: 'root');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schemaDefinitions = $this->discoverAndParseSchemas($input, $output);

        if (empty($schemaDefinitions)) {
            return Command::SUCCESS;
        }

        $dbDefinitions = $this->introspectDatabase($input, $output);

        if ($dbDefinitions === null) {
            return Command::FAILURE;
        }

        $this->compareAndDisplayDifferences($output, $schemaDefinitions, $dbDefinitions);

        return Command::SUCCESS;
    }

    /**
     * Discover and parse schema classes from the filesystem.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return array
     */
    protected function discoverAndParseSchemas(InputInterface $input, OutputInterface $output): array
    {
        $path = $input->getArgument('path');

        $output->writeln("<info>Scanning for schema classes in: {$path}</info>");

        $classNames = $this->findSchemaClasses($path);

        if (empty($classNames)) {
            $output->writeln('<comment>No schema classes found.</comment>');
            return [];
        }

        $this->displayFoundClasses($output, $classNames);

        return $this->parseSchemaClasses($output, $classNames);
    }

    /**
     * Find all schema classes in a given path.
     *
     * @param string $path
     * @return array
     */
    protected function findSchemaClasses(string $path): array
    {
        $finder = new ClassFinder();

        return $finder->findInDirectory($path);
    }

    /**
     * Display the list of found classes.
     *
     * @param OutputInterface $output
     * @param array $classNames
     * @return void
     */
    protected function displayFoundClasses(OutputInterface $output, array $classNames): void
    {
        $output->writeln(sprintf('Found %d schema class(es):', count($classNames)));

        foreach ($classNames as $className) {
            $output->writeln(" - {$className}");
        }

        $output->writeln('');
    }


    /**
     * Parse schema classes into definitions.
     *
     * @param OutputInterface $output
     * @param array $classNames
     * @return array
     */
    protected function parseSchemaClasses(OutputInterface $output, array $classNames): array
    {
        $output->writeln('<info>Parsing schema definitions...</info>');

        $parser = new SchemaParser();
        $definitions = [];

        foreach ($classNames as $className) {
            if ($definition = $this->parseClass($output, $parser, $className)) {
                $definitions[$definition->tableName()] = $definition;
            }
        }

        $output->writeln('');

        return $definitions;
    }

    /**
     * Parse a single class into a definition.
     *
     * @param OutputInterface $output
     * @param SchemaParser $parser
     * @param string $className
     * @return TableDefinition|null
     */
    protected function parseClass(OutputInterface $output, SchemaParser $parser, string $className): ?TableDefinition
    {
        try {
            $definition = $parser->parse($className);
            $output->writeln("  ✓ {$className} → table '{$definition->tableName}'");

            return $definition;
        } catch (\Exception $e) {
            $output->writeln("<error>  ✗ {$className}: {$e->getMessage()}</error>");

            return null;
        }
    }

    /**
     * Introspect the database schema.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return array|null
     */
    protected function introspectDatabase(InputInterface $input, OutputInterface $output): ?array
    {
        $output->writeln('<info>Connecting to database...</info>');

        $pdo = $this->connectToDatabase($input, $output);

        if (! $pdo) {
            return null;
        }

        $output->writeln(" ✓ Connected");
        $output->writeln('');
        $output->writeln('<info>Introspecting database schema...</info>');

        $driver = new MySqlDriver($pdo);
        $definitions = $driver->getCurrentSchema();

        $output->writeln(sprintf('Found %d table(s) in database', count($definitions)));
        $output->writeln('');

        return $definitions;
    }

    /**
     * Create a database connection.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return PDO|null
     */
    protected function connectToDatabase(InputInterface $input, OutputInterface $output): ?PDO
    {
        try {
            return new PDO(
                $this->buildDsn($input),
                $input->getOption('username'),
                $input->getOption('password'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            $output->writeln("<error>Failed to connect: {$e->getMessage()}</error>");

            return null;
        }
    }

    /**
     * Build the DSN string
     *
     * @param InputInterface $input
     * @return string
     */
    protected function buildDsn(InputInterface $input): string
    {
        return sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $input->getOption('host'),
            $input->getOption('port'),
            $input->getOption('database')
        );
    }

    /**
     * Compare schemas and display differences.
     *
     * @param OutputInterface $output
     * @param array $schemaDefinitions
     * @param array $dbDefinitions
     * @return void
     */
    protected function compareAndDisplayDifferences(
        OutputInterface $output,
        array $schemaDefinitions,
        array $dbDefinitions
    ): void {
        $output->writeln('<info>Comparing schemas...</info>');
        $output->writeln('');

        $comparator = new TableComparator();
        $hasChanges = false;

        foreach ($schemaDefinitions as $tableName => $schemaTable) {
            if ($this->tableExistsInDatabase($tableName, $dbDefinitions)) {
                $hasChanges = $this->compareAndDisplayTable($output, $comparator, $tableName, $dbDefinitions[$tableName], $schemaTable) || $hasChanges;
            } else {
                $this->displayTableMissing($output, $tableName);
                $hasChanges = true;
            }
        }

        if (! $hasChanges) {
            $output->writeln('<info>✅ No changes detected. Schema is in sync!</info>');
        }
    }

    /**
     * Check if a table exists in the database definitions.
     * @param string $tableName
     * @param array $dbDefinitions
     * @return bool
     */
    protected function tableExistsInDatabase(string $tableName, array $dbDefinitions): bool
    {
        return isset($dbDefinitions[$tableName]);
    }

    /**
     * Display that a table is missing from the database.
     * @param OutputInterface $output
     * @param string $tableName
     * @return void
     */
    protected function displayTableMissing(OutputInterface $output, string $tableName): void
    {
        $output->writeln("<comment>+ Table '{$tableName}' does not exist in database (needs CREATE)</comment>");
    }

    /**
     * Compare a single table and display changes.
     *
     * @param OutputInterface $output
     * @param TableComparator $comparator
     * @param string $tableName
     * @param TableDefinition $dbTable
     * @param TableDefinition $schemaTable
     * @return bool
     */
    protected function compareAndDisplayTable(
        OutputInterface $output,
        TableComparator $comparator,
        string $tableName,
        TableDefinition $dbTable,
        TableDefinition $schemaTable
    ): bool {
        $changes = $comparator->compare($dbTable, $schemaTable);

        if (! $changes->hasChanges()) {
            return false;
        }

        $this->displayTableChanges($output, $tableName, $changes);

        return true;
    }

    /**
     * Display changes for a single table.
     *
     * @param OutputInterface $output
     * @param string $tableName
     * @param TableChanges $changes
     * @return void
     */
    protected function displayTableChanges(OutputInterface $output, string $tableName, TableChanges $changes): void
    {
        $output->writeln("<comment>~ Table '{$tableName}' has changes:</comment>");

        foreach ($changes->addedColumns as $col) {
            $output->writeln("  <fg=green>+ Column: {$col->name()} ({$col->sqlType()})</>");
        }

        foreach ($changes->modifiedColumns as $col) {
            $output->writeln("  <fg=yellow>~ Column: {$col->name()} (modified)</>");
        }

        foreach ($changes->removedColumns as $colName) {
            $output->writeln("  <fg=red>- Column: {$colName}</>");
        }

        $output->writeln('');
    }
}
