<?php

declare(strict_types=1);

namespace Atlas\Console\Commands;

use Atlas\Analysis\MySqlDestructiveChangeAnalyzer;
use Atlas\Comparison\TableComparator;
use Atlas\Connection\ConnectionManager;
use Atlas\Database\Drivers\MySqlDriver;
use Atlas\Database\Drivers\MySqlTypeNormalizer;
use Atlas\Schema\Discovery\ClassFinder;
use Atlas\Schema\Discovery\YamlSchemaFinder;
use Atlas\Schema\Grammars\MySqlGrammar;
use Atlas\Schema\Parser\SchemaParser;
use Atlas\Schema\Parser\YamlSchemaParser;
use Atlas\Support\ProjectRootFinder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class DiffCommand extends Command
{
    public function __construct(
        private ConnectionManager $connectionManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('diff')
            ->setDescription('Generate SQL to migrate database to match schema definitions')
            ->addOption('path', 'p', InputOption::VALUE_OPTIONAL, 'Path to scan for schema classes')
            ->addOption('yaml-path', 'y', InputOption::VALUE_OPTIONAL, 'Path to scan for YAML schema files')
            ->addOption('namespace', null, InputOption::VALUE_OPTIONAL, 'Namespace to scan for schema classes', 'App')
            ->addOption('connection', 'c', InputOption::VALUE_OPTIONAL, 'Database connection name', 'default')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output file path (optional)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $connectionName = $input->getOption('connection');
            $pdo = $this->connectionManager->connection($connectionName);
            $driver = new MySqlDriver($pdo);
            $normalizer = new MySqlTypeNormalizer();
            $parser = new SchemaParser($normalizer);
            $analyzer = new MySqlDestructiveChangeAnalyzer();
            $grammar = new MySqlGrammar();

            // Discover schemas
            $io->section('Discovering Schemas');
            $codeSchemas = $this->discoverSchemas($input, $io, $parser, $normalizer);

            if (empty($codeSchemas)) {
                $io->warning('No schema definitions found.');
                return Command::SUCCESS;
            }

            $io->note(sprintf('Found %d table definition(s)', count($codeSchemas)));

            // Introspect database
            $io->section('Introspecting Database');
            $dbSchemas = $driver->getCurrentSchema();
            $io->text(sprintf('Found %d existing table(s) in database', count($dbSchemas)));

            // Compare and detect changes
            $changes = $this->detectChanges($codeSchemas, $dbSchemas, $analyzer);

            if ($this->noChangesDetected($changes)) {
                $io->success('Database schema is up to date!');
                return Command::SUCCESS;
            }

            // Generate SQL
            $sql = $this->generateMigrationSql($changes, $grammar);

            $this->displayResults($io, $sql, $input);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    protected function discoverSchemas(
        InputInterface $input,
        SymfonyStyle $io,
        SchemaParser $parser,
        MySqlTypeNormalizer $normalizer
    ): array {
        $yamlPath = $input->getOption('yaml-path');
        $phpPath = $input->getOption('path');

        $schemas = [];

        // Auto-discover from project root if no paths specified
        if ($yamlPath === null && $phpPath === null) {
            $projectRoot = ProjectRootFinder::find();
            if ($projectRoot !== null) {
                $io->note("Scanning project root: {$projectRoot}");
                $phpPath = $projectRoot;
                $yamlPath = $projectRoot;
            }
        }

        // Discover YAML schemas
        if ($yamlPath) {
            $io->text("Scanning YAML path: {$yamlPath}");
            $yamlSchemas = $this->discoverYamlSchemas($yamlPath, $normalizer);
            foreach ($yamlSchemas as $tableName => $definition) {
                $schemas[$tableName] = $definition;
                $io->text("  ✓ Loaded YAML: {$tableName}");
            }
        }

        // Discover PHP schemas
        if ($phpPath) {
            $io->text("Scanning PHP path: {$phpPath}");
            $finder = new ClassFinder();
            $schemaClasses = $finder->findInDirectory($phpPath);

            foreach ($schemaClasses as $class) {
                $definition = $parser->parse($class);
                $schemas[$definition->tableName] = $definition;
                $io->text("  ✓ Parsed PHP: {$class}");
            }
        }

        return $schemas;
    }

    protected function discoverYamlSchemas(string $path, MySqlTypeNormalizer $normalizer): array
    {
        $yamlParser = new YamlSchemaParser($normalizer);
        $finder = new YamlSchemaFinder();

        $files = $finder->findInDirectory($path);
        $schemas = [];

        foreach ($files as $file) {
            $parsed = $yamlParser->parseFile($file);
            foreach ($parsed as $tableName => $definition) {
                if (isset($schemas[$tableName])) {
                    throw new \Atlas\Exceptions\SchemaException(
                        "Duplicate table definition: {$tableName}"
                    );
                }
                $schemas[$tableName] = $definition;
            }
        }

        return $schemas;
    }

    protected function detectChanges(array $codeSchemas, array $dbSchemas, $analyzer): array
    {
        $changes = [
            'new_tables' => [],
            'dropped_tables' => [],
            'modified_tables' => [],
        ];

        $comparator = new TableComparator($analyzer);

        // find new and modified tables
        foreach ($codeSchemas as $tableName => $codeTable) {
            if (! isset($dbSchemas[$tableName])) {
                $changes['new_tables'][$tableName] = $codeTable;
            } else {
                $diff = $comparator->compare($dbSchemas[$tableName], $codeTable);

                if (! $diff->isEmpty()) {
                    $changes['modified_tables'][$tableName] = $diff;
                }
            }
        }

        // find dropped tables
        $codeTableNames = array_map(fn($t) => $t->tableName, $codeSchemas);
        foreach ($dbSchemas as $tableName => $dbTable) {
            if (! in_array($tableName, $codeTableNames, true)) {
                $changes['dropped_tables'][$tableName] = $dbTable;
            }
        }

        return $changes;
    }

    protected function noChangesDetected(array $changes): bool
    {
        return empty($changes['new_tables'])
            && empty($changes['dropped_tables'])
            && empty($changes['modified_tables']);
    }

    protected function generateMigrationSql(array $changes, MySqlGrammar $grammar): array
    {
        $sql = [];

        // New tables
        foreach ($changes['new_tables'] as $tableName => $table) {
            $sql[] = $grammar->createTable($table);
        }

        // Modified tables
        foreach ($changes['modified_tables'] as $tableName => $diff) {
            $statements = $grammar->generateAlter($diff);
            $sql = array_merge($sql, $statements);
        }

        // Dropped tables
        foreach ($changes['dropped_tables'] as $tableName => $table) {
            $sql[] = "DROP TABLE `{$tableName}`;";
        }

        return $sql;
    }

    protected function displayResults(SymfonyStyle $io, array $sql, InputInterface $input): void
    {
        $io->section('Migration SQL');

        foreach ($sql as $statement) {
            $io->writeln($statement . ';');
        }

        $io->newLine();
        $io->note(sprintf('Generated %d SQL statement(s)', count($sql)));

        $this->saveToFileIfRequested($io, $sql, $input);
    }

    protected function saveToFileIfRequested(SymfonyStyle $io, array $sql, InputInterface $input): void
    {
        $outputPath = $input->getOption('output');

        if ($outputPath === null) {
            return;
        }

        $this->saveSqlToFile($outputPath, $sql);
        $io->success("SQL saved to: {$outputPath}");
    }

    protected function saveSqlToFile(string $path, array $sql): void
    {
        $content = implode(";\n", $sql) . ";\n";
        file_put_contents($path, $content);
    }
}
