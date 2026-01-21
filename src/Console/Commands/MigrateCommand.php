<?php

namespace Atlas\Console\Commands;

use Atlas\Analysis\DestructivenessLevel;
use Atlas\Analysis\MySqlDestructiveChangeAnalyzer;
use Atlas\Changes\TableChanges;
use Atlas\Comparison\TableComparator;
use Atlas\Connection\ConnectionManager;
use Atlas\Database\Drivers\MySqlDriver;
use Atlas\Schema\Grammars\MySqlGrammar;
use Atlas\Database\Drivers\MySqlTypeNormalizer;
use Atlas\Schema\Parser\SchemaParser;
use Atlas\Schema\Discovery\ClassFinder;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

class MigrateCommand extends Command
{
    protected static $defaultName = 'schema:migrate';
    protected static $defaultDescription = 'Migrate database schema to match code definitions.';

    public function __construct(
        private ?ConnectionManager $connectionManager = null,
    ) {
        parent::__construct('schema:migrate');
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Path to schema classes', 'src')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show changes without executing')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip all confirmation prompts')
            ->addOption('manual-all', 'm', InputOption::VALUE_NONE, 'Prompt for approval on ALL changes, including safe ones')
            ->addOption('yaml-path', null, InputOption::VALUE_REQUIRED, 'Path to YAML schema files')
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Connection name to use', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connectionName = $input->getOption('connection');

        // Get database dependencies
        $pdo = $this->getConnection($connectionName);
        $driver = $this->getDriver($connectionName, $pdo);
        $grammar = $this->getGrammar($connectionName);
        $normalizer = $this->getNormalizer($connectionName);
        $parser = new SchemaParser($normalizer);
        $analyzer = new MySqlDestructiveChangeAnalyzer();

        // Discover schemas (YAML or PHP)
        $io->section('Discovering Schemas');

        try {
            $codeSchemas = $this->discoverSchemas($input, $io, $parser, $normalizer);
        } catch (\Atlas\Exceptions\SchemaException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if (empty($codeSchemas)) {
            $io->error('No schemas found');
            return Command::FAILURE;
        }

        $io->text(sprintf('Found %d schema(s)', count($codeSchemas)));

        // Introspect DB
        $io->section('Introspecting Database');
        $dbSchemas = $driver->getCurrentSchema();
        $io->text(sprintf('Found %d existing table(s) in database', count($dbSchemas)));

        // Compare and detect changes
        $io->section('Analyzing Changes');
        $changes = $this->detectChanges($codeSchemas, $dbSchemas, $analyzer);

        if ($this->noChangesDetected($changes)) {
            $io->success('Database schema is up to date!');
            return Command::SUCCESS;
        }

        // Display changes grouped by severity
        $this->displayChanges($io, $changes);

        // Dry run mode
        if ($input->getOption('dry-run')) {
            $io->note('Dry run mode - no changes will be applied');
            $this->displaySQL($io, $changes, $grammar);
            return Command::SUCCESS;
        }

        // Determine execution mode
        $hasDestructive = $this->hasDestructiveChanges($changes);
        $forceMode = $input->getOption('force');

        if ($hasDestructive && !$forceMode) {
            // INTERACTIVE MODE
            $io->section('Interactive Migration Mode');
            $io->text('Destructive changes detected. You will be prompted to approve each one.');
            $io->newLine();

            $this->executeInteractiveMigration($pdo, $changes, $grammar, $io, $input);
        } else {
            // BATCH MODE
            if ($hasDestructive && $forceMode) {
                $io->warning('Force mode enabled - executing all changes without prompts');
            }

            $io->section('Executing Migration');
            $this->executeMigration($pdo, $changes, $grammar, $io);
        }

        $io->success('Schema migration completed successfully!');

        return Command::SUCCESS;
    }

    protected function detectChanges(array $codeSchemas, array $dbSchemas, $analyzer): array
    {
        $changes = [
            'new_tables' => [],
            'dropped_tables' => [],
            'modified_tables' => [],
        ];

        $comparator = new TableComparator($analyzer);

        foreach ($codeSchemas as $className => $codeTable) {
            $tableName = $codeTable->tableName;

            if (! isset($dbSchemas[$tableName])) {
                $changes['new_tables'][$tableName] = $codeTable;
            } else {
                $diff = $comparator->compare($dbSchemas[$tableName], $codeTable);

                if ($diff->hasChanges()) {
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

    protected function hasDestructiveChanges(array $changes): bool
    {
        if (!empty($changes['dropped_tables'])) {
            return true;
        }

        foreach ($changes['modified_tables'] as $diff) {
            if ($diff->hasDestructiveChanges() || $diff->hasPotentiallyDestructiveChanges()) {
                return true;
            }
        }

        return false;
    }

    protected function displayChanges(SymfonyStyle $io, array $changes): void
    {
        // New tables (safe)
        if (!empty($changes['new_tables'])) {
            $io->section('✓ New Tables (Safe)');
            foreach ($changes['new_tables'] as $tableName => $table) {
                $io->text("  <fg=green>+ CREATE TABLE `{$tableName}`</>");
            }
        }

        // Modified tables - group by destructiveness
        if (!empty($changes['modified_tables'])) {
            $safe = [];
            $potentially = [];
            $definitely = [];

            foreach ($changes['modified_tables'] as $tableName => $diff) {
                if ($diff->hasDestructiveChanges()) {
                    $definitely[$tableName] = $diff;
                } elseif ($diff->hasPotentiallyDestructiveChanges()) {
                    $potentially[$tableName] = $diff;
                } else {
                    $safe[$tableName] = $diff;
                }
            }

            // Safe changes
            if (!empty($safe)) {
                $io->section('✓ Safe Modifications');
                foreach ($safe as $tableName => $diff) {
                    $this->displayTableDiff($io, $tableName, $diff, 'green');
                }
            }

            // Potentially destructive
            if (!empty($potentially)) {
                $io->section('⚠ Potentially Destructive Changes');
                foreach ($potentially as $tableName => $diff) {
                    $this->displayTableDiff($io, $tableName, $diff, 'yellow');
                }
            }

            // Definitely destructive
            if (!empty($definitely)) {
                $io->section('⛔ Definitely Destructive Changes');
                foreach ($definitely as $tableName => $diff) {
                    $this->displayTableDiff($io, $tableName, $diff, 'red');
                }
            }
        }

        // Dropped tables (destructive)
        if (!empty($changes['dropped_tables'])) {
            $io->section('⛔ Dropped Tables (Destructive)');
            foreach ($changes['dropped_tables'] as $tableName => $table) {
                $io->text("  <fg=red>- DROP TABLE `{$tableName}`</>");
            }
        }
    }

    protected function displayTableDiff(SymfonyStyle $io, string $tableName, $diff, string $color): void
    {
        $io->text("<fg={$color}>  Table: `{$tableName}`</>");

        foreach ($diff->addedColumns as $col) {
            $io->text("    <fg=green>+ ADD COLUMN `{$col->name()}` {$col->sqlType()}</>");
        }

        foreach ($diff->modifiedColumns as $col) {
            $level = $diff->modificationDestructiveness[$col->name()] ?? DestructivenessLevel::SAFE;
            $symbol = match($level) {
                DestructivenessLevel::DEFINITELY_DESTRUCTIVE => '⛔',
                DestructivenessLevel::POTENTIALLY_DESTRUCTIVE => '⚠',
                default => '~'
            };
            $io->text("    <fg={$color}>{$symbol} MODIFY COLUMN `{$col->name()}` {$col->sqlType()}</>");
        }

        foreach ($diff->removedColumns as $colName) {
            $io->text("    <fg=red>- DROP COLUMN `{$colName}`</>");
        }
    }

    protected function displaySQL(SymfonyStyle $io, array $changes, MySqlGrammar $grammar): void
    {
        $io->section('Generated SQL');

        // New tables
        foreach ($changes['new_tables'] as $table) {
            $sql = $grammar->createTable($table);
            $io->text($sql . ';');
            $io->newLine();
        }

        // Modified tables
        foreach ($changes['modified_tables'] as $diff) {
            $alterStatements = $grammar->generateAlter($diff);
            foreach ($alterStatements as $stmt) {
                $io->text($stmt . ';');
            }
            $io->newLine();
        }

        // Dropped tables
        foreach ($changes['dropped_tables'] as $tableName => $table) {
            $io->text("DROP TABLE `{$tableName}`;");
        }
    }

    protected function executeMigration(PDO $pdo, array $changes, MySqlGrammar $grammar, SymfonyStyle $io): void
    {
        // Note: MySQL DDL statements auto-commit, so transactions don't work for schema changes
        // We execute each statement individually

        // Create new tables
        foreach ($changes['new_tables'] as $table) {
            $sql = $grammar->createTable($table);
            $pdo->exec($sql);
            $io->text("  ✓ Created table `{$table->tableName}`");
        }

        // Modify existing tables
        foreach ($changes['modified_tables'] as $diff) {
            $alterStatements = $grammar->generateAlter($diff);
            foreach ($alterStatements as $stmt) {
                $pdo->exec($stmt);
            }
            $io->text("  ✓ Modified table `{$diff->tableName}`");
        }

        // Drop tables
        foreach ($changes['dropped_tables'] as $tableName => $table) {
            $pdo->exec("DROP TABLE `{$tableName}`");
            $io->text("  ✓ Dropped table `{$tableName}`");
        }
    }

    /**
     * Execute migration in interactive mode with per-change approval prompts.
     */
    protected function executeInteractiveMigration(
        PDO $pdo,
        array $changes,
        MySqlGrammar $grammar,
        SymfonyStyle $io,
        InputInterface $input
    ): void {
        $flattenedChanges = $this->flattenChangesToList($changes, $grammar);
        $manualAll = $input->getOption('manual-all');

        // Separate safe and destructive changes
        $safeChanges = array_filter(
            $flattenedChanges,
            fn($c) => $c['level'] === DestructivenessLevel::SAFE
        );
        $destructiveChanges = array_filter(
            $flattenedChanges,
            fn($c) => $c['level'] !== DestructivenessLevel::SAFE
        );

        // Note: MySQL DDL statements auto-commit, so no transactions

        // Execute safe changes
        if (!empty($safeChanges)) {
            if ($manualAll) {
                // Manual mode: prompt for each safe change
                foreach ($safeChanges as $change) {
                    $result = $this->executeChangeWithPrompt($pdo, $io, $change, $grammar);
                    if ($result === 'aborted') {
                        $io->warning('Migration aborted by user');
                        return;
                    }
                }
            } else {
                // Auto mode: execute with notifications
                $io->section('Applying Safe Changes');
                foreach ($safeChanges as $change) {
                    $pdo->exec($change['sql']);
                    $io->text("  ✓ " . $change['description']);
                }
                $io->newLine();
            }
        }

        // Execute destructive changes with prompts
        if (!empty($destructiveChanges)) {
            $io->section('Destructive Changes - Manual Approval Required');
            $io->text(sprintf(
                'Found %d destructive change(s) requiring your approval',
                count($destructiveChanges)
            ));
            $io->newLine();

            $skippedChanges = [];

            foreach ($destructiveChanges as $index => $change) {
                // Display progress
                $io->text(sprintf(
                    '<fg=cyan>[Change %d of %d]</>',
                    $index + 1,
                    count($destructiveChanges)
                ));

                $result = $this->executeChangeWithPrompt($pdo, $io, $change, $grammar);

                if ($result === 'skipped') {
                    $skippedChanges[] = $change;
                } elseif ($result === 'aborted') {
                    $io->warning('Migration aborted by user');
                    return;
                }

                $io->newLine();
            }

            // Show summary of skipped changes
            if (!empty($skippedChanges)) {
                $io->section('Skipped Changes Summary');
                foreach ($skippedChanges as $change) {
                    $io->text("  ⊘ {$change['description']}");
                }
            }
        }
    }

    /**
     * Flatten all changes into a single ordered list of atomic changes with metadata.
     * Order: by table, then by destructiveness within each table.
     */
    protected function flattenChangesToList(array $changes, MySqlGrammar $grammar): array
    {
        $flattened = [];

        // 1. New tables (always safe, execute first)
        foreach ($changes['new_tables'] as $tableName => $table) {
            $flattened[] = [
                'id' => "create_table_{$tableName}",
                'type' => 'create_table',
                'tableName' => $tableName,
                'level' => DestructivenessLevel::SAFE,
                'description' => "CREATE TABLE `{$tableName}`",
                'sql' => $grammar->createTable($table),
                'details' => ['table' => $table],
                'canPreview' => false,
            ];
        }

        // 2. Modified tables (ordered by table, then by severity within each table)
        foreach ($changes['modified_tables'] as $tableName => $diff) {
            // Dropped columns first (DEFINITELY_DESTRUCTIVE)
            foreach ($diff->removedColumns as $columnName) {
                $flattened[] = [
                    'id' => "{$tableName}_drop_{$columnName}",
                    'type' => 'drop_column',
                    'tableName' => $tableName,
                    'level' => DestructivenessLevel::DEFINITELY_DESTRUCTIVE,
                    'description' => "DROP COLUMN `{$columnName}` from `{$tableName}`",
                    'sql' => $grammar->generateDropColumn($tableName, $columnName),
                    'details' => ['columnName' => $columnName],
                    'canPreview' => true,
                ];
            }

            // Modified columns (ordered by destructiveness)
            $groupedMods = $this->groupModificationsBySeverity($diff);

            foreach ($groupedMods['definitely_destructive'] as $column) {
                $flattened[] = [
                    'id' => "{$tableName}_modify_{$column->name()}",
                    'type' => 'modify_column',
                    'tableName' => $tableName,
                    'level' => DestructivenessLevel::DEFINITELY_DESTRUCTIVE,
                    'description' => "MODIFY COLUMN `{$column->name()}` in `{$tableName}` to {$column->sqlType()}",
                    'sql' => $grammar->generateModifyColumn($tableName, $column),
                    'details' => ['column' => $column],
                    'canPreview' => true,
                ];
            }

            foreach ($groupedMods['potentially_destructive'] as $column) {
                $flattened[] = [
                    'id' => "{$tableName}_modify_{$column->name()}",
                    'type' => 'modify_column',
                    'tableName' => $tableName,
                    'level' => DestructivenessLevel::POTENTIALLY_DESTRUCTIVE,
                    'description' => "MODIFY COLUMN `{$column->name()}` in `{$tableName}` to {$column->sqlType()}",
                    'sql' => $grammar->generateModifyColumn($tableName, $column),
                    'details' => ['column' => $column],
                    'canPreview' => true,
                ];
            }

            foreach ($groupedMods['safe'] as $column) {
                $flattened[] = [
                    'id' => "{$tableName}_modify_{$column->name()}",
                    'type' => 'modify_column',
                    'tableName' => $tableName,
                    'level' => DestructivenessLevel::SAFE,
                    'description' => "MODIFY COLUMN `{$column->name()}` in `{$tableName}` to {$column->sqlType()}",
                    'sql' => $grammar->generateModifyColumn($tableName, $column),
                    'details' => ['column' => $column],
                    'canPreview' => false,
                ];
            }

            // Added columns (SAFE)
            foreach ($diff->addedColumns as $column) {
                $flattened[] = [
                    'id' => "{$tableName}_add_{$column->name()}",
                    'type' => 'add_column',
                    'tableName' => $tableName,
                    'level' => DestructivenessLevel::SAFE,
                    'description' => "ADD COLUMN `{$column->name()}` to `{$tableName}`",
                    'sql' => $grammar->generateAddColumn($tableName, $column),
                    'details' => ['column' => $column],
                    'canPreview' => false,
                ];
            }
        }

        // 3. Dropped tables (DEFINITELY_DESTRUCTIVE, execute last)
        foreach ($changes['dropped_tables'] as $tableName => $table) {
            $flattened[] = [
                'id' => "drop_table_{$tableName}",
                'type' => 'drop_table',
                'tableName' => $tableName,
                'level' => DestructivenessLevel::DEFINITELY_DESTRUCTIVE,
                'description' => "DROP TABLE `{$tableName}`",
                'sql' => "DROP TABLE `{$tableName}`",
                'details' => ['table' => $table],
                'canPreview' => true,
            ];
        }

        return $flattened;
    }

    /**
     * Group modified columns by their destructiveness level.
     */
    protected function groupModificationsBySeverity(TableChanges $diff): array
    {
        $grouped = [
            'definitely_destructive' => [],
            'potentially_destructive' => [],
            'safe' => [],
        ];

        foreach ($diff->modifiedColumns as $column) {
            $level = $diff->modificationDestructiveness[$column->name()];

            if ($level === DestructivenessLevel::DEFINITELY_DESTRUCTIVE) {
                $grouped['definitely_destructive'][] = $column;
            } elseif ($level === DestructivenessLevel::POTENTIALLY_DESTRUCTIVE) {
                $grouped['potentially_destructive'][] = $column;
            } else {
                $grouped['safe'][] = $column;
            }
        }

        return $grouped;
    }

    /**
     * Execute a single change with user prompt.
     * Returns: 'executed', 'skipped', or 'aborted'
     */
    protected function executeChangeWithPrompt(PDO $pdo, SymfonyStyle $io, array $change, MySqlGrammar $grammar): string
    {
        // Display the change with styling based on destructiveness
        $this->displaySingleChange($io, $change);

        // Get user decision
        while (true) {
            $decision = $this->promptForChange($io, $change);

            if ($decision === 'abort') {
                return 'aborted';
            }

            if ($decision === 'show') {
                $this->showAffectedData($pdo, $io, $change, $grammar);
                continue; // Re-prompt
            }

            if ($decision === 'no') {
                $io->text('  <fg=yellow>⊘ Skipped</>');
                return 'skipped';
            }

            if ($decision === 'yes') {
                try {
                    $pdo->exec($change['sql']);
                    $io->text('  <fg=green>✓ Applied</>');
                    return 'executed';
                } catch (\PDOException $e) {
                    $io->error("Failed to execute: {$change['description']}");
                    $io->text("Error: " . $e->getMessage());

                    $retryQuestion = new ConfirmationQuestion('Retry this change? [y/N] ', false);
                    if (!$io->askQuestion($retryQuestion)) {
                        return 'aborted';
                    }
                    // Loop will retry
                }
            }
        }
    }

    /**
     * Display a single change with appropriate formatting.
     */
    protected function displaySingleChange(SymfonyStyle $io, array $change): void
    {
        $levelSymbol = match($change['level']) {
            DestructivenessLevel::DEFINITELY_DESTRUCTIVE => '⛔',
            DestructivenessLevel::POTENTIALLY_DESTRUCTIVE => '⚠',
            default => '✓'
        };

        $levelText = match($change['level']) {
            DestructivenessLevel::DEFINITELY_DESTRUCTIVE => 'DEFINITELY DESTRUCTIVE',
            DestructivenessLevel::POTENTIALLY_DESTRUCTIVE => 'POTENTIALLY DESTRUCTIVE',
            default => 'SAFE'
        };

        $levelColor = match($change['level']) {
            DestructivenessLevel::DEFINITELY_DESTRUCTIVE => 'red',
            DestructivenessLevel::POTENTIALLY_DESTRUCTIVE => 'yellow',
            default => 'green'
        };

        $io->writeln('┌' . str_repeat('─', 70) . '┐');
        $io->writeln(sprintf(
            "│ <fg=%s>%s %s</> - Table: `%s`",
            $levelColor,
            $levelSymbol,
            $levelText,
            $change['tableName']
        ));
        $io->writeln('│');
        $io->writeln("│ {$change['description']}");
        $io->writeln('│');
        $io->writeln("│ SQL: {$change['sql']}");

        if ($change['level'] !== DestructivenessLevel::SAFE) {
            $io->writeln('│');
            $io->writeln("│ <fg=yellow>⚠️  This change may result in data loss!</>");
        }

        $io->writeln('└' . str_repeat('─', 70) . '┘');
    }

    /**
     * Prompt user for decision on a change.
     * Returns: 'yes', 'no', 'abort', or 'show'
     */
    protected function promptForChange(SymfonyStyle $io, array $change): string
    {
        $options = ['y' => 'Yes - Apply', 'n' => 'No - Skip', 'a' => 'Abort - Cancel migration'];

        if ($change['canPreview']) {
            $options['s'] = 'Show - Preview affected data';
        }

        $question = new ChoiceQuestion(
            'What would you like to do?',
            $options,
            'y'
        );

        $answer = $io->askQuestion($question);

        return match($answer) {
            'y', 'Yes - Apply' => 'yes',
            'n', 'No - Skip' => 'no',
            'a', 'Abort - Cancel migration' => 'abort',
            's', 'Show - Preview affected data' => 'show',
            default => 'yes',
        };
    }

    /**
     * Show affected data for preview.
     */
    protected function showAffectedData(PDO $pdo, SymfonyStyle $io, array $change, MySqlGrammar $grammar): void
    {
        try {
            if ($change['type'] === 'drop_column') {
                $sql = $grammar->generatePreviewQuery(
                    $change['tableName'],
                    $change['details']['columnName']
                );

                $stmt = $pdo->query($sql);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($rows)) {
                    $io->text('<fg=green>No data in this column (all NULL values)</>');
                } else {
                    $io->text('Sample data from column (showing up to 10 rows):');
                    $io->table(
                        array_keys($rows[0]),
                        array_slice($rows, 0, 10)
                    );
                }
            } elseif ($change['type'] === 'drop_table') {
                $sql = "SELECT COUNT(*) as row_count FROM `{$change['tableName']}`";
                $stmt = $pdo->query($sql);
                $count = $stmt->fetch(PDO::FETCH_ASSOC)['row_count'];

                $io->text(sprintf(
                    '<fg=yellow>This table contains %d row(s) that will be deleted</>',
                    $count
                ));
            }
        } catch (\PDOException $e) {
            $io->warning("Could not preview data: " . $e->getMessage());
        }
    }

    // ==========================================
    // Connection & Dependency Resolution
    // ==========================================

    protected function getConnection(string $connectionName): PDO
    {
        if ($this->connectionManager) {
            return $this->connectionManager->connection($connectionName);
        }

        return $this->createDatabaseConnection();
    }

    protected function getDriver(string $connectionName, PDO $pdo): \Atlas\Database\Drivers\MySqlDriver
    {
        // TODO: Use ConnectionManager to get driver based on connection type
        return new MySqlDriver($pdo);
    }

    protected function getGrammar(string $connectionName): MySqlGrammar
    {
        // TODO: Use ConnectionManager to get grammar based on connection type
        return new MySqlGrammar();
    }

    protected function getNormalizer(string $connectionName): MySqlTypeNormalizer
    {
        // TODO: Use ConnectionManager to get normalizer based on connection type
        return new MySqlTypeNormalizer();
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
        // Scan PHP path if: (a) no YAML path given, or (b) explicitly provided with --path
        $explicitlyProvidedPath = $input->getOption('path') !== 'src';
        $shouldScanPhp = ! $yamlPath || $explicitlyProvidedPath;

        if ($shouldScanPhp && $phpPath) {
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
        $yamlParser = new \Atlas\Schema\Parser\YamlSchemaParser($normalizer);
        $finder = new \Atlas\Schema\Discovery\YamlSchemaFinder();

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

    protected function createDatabaseConnection(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: 'localhost',
            getenv('DB_PORT') ?: '3306',
            getenv('DB_DATABASE') ?: 'test'
        );

        return new PDO(
            $dsn,
            getenv('DB_USERNAME') ?: 'root',
            getenv('DB_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}