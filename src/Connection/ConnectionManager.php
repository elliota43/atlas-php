<?php

declare(strict_types=1);

namespace Atlas\Connection;

use Atlas\Database\DatabaseDriver;
use Atlas\Database\Drivers\MySqlDriver;
use Atlas\Database\Drivers\MySqlTypeNormalizer;
use Atlas\Database\TypeNormalizerInterface;
use Atlas\Exceptions\ConnectionException;
use Atlas\Schema\Comparison\SchemaComparator;
use Atlas\Schema\Discovery\ClassFinder;
use Atlas\Schema\Grammars\GrammarInterface;
use Atlas\Schema\Grammars\MySqlGrammar;
use Atlas\Schema\Loader\SchemaLoader;
use Atlas\Schema\Parser\SchemaParser;
use Atlas\Schema\Parser\YamlSchemaParser;
use PDO;

final class ConnectionManager
{
    private array $connections = [];

    public function __construct(
        private array $config,
    ) {}

    /**
     * Get a database connection by name.
     */
    public function connection(string $name = 'default'): PDO
    {
        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        if (! isset($this->config[$name])) {
            throw ConnectionException::connectionNotFound($name);
        }

        $this->connections[$name] = $this->createConnection($this->config[$name]);

        return $this->connections[$name];
    }

    /**
     * Get the driver name for a connection.
     */
    public function getDriverName(string $connectionName = 'default'): string
    {
        if (! isset($this->config[$connectionName])) {
            throw ConnectionException::connectionNotFound($connectionName);
        }

        return $this->config[$connectionName]['driver'];
    }

    /**
     * Create a SchemaLoader for the specified connection.
     */
    public function getSchemaLoader(string $connectionName = 'default'): SchemaLoader
    {
        $normalizer = $this->createTypeNormalizer($connectionName);

        return new SchemaLoader(
            yamlParser: new YamlSchemaParser($normalizer),
            phpParser: new SchemaParser($normalizer),
            classFinder: new ClassFinder(),
            normalizer: $normalizer
        );
    }

    /**
     * Create a SchemaComparator for the specified connection.
     */
    public function getSchemaComparator(string $connectionName = 'default'): SchemaComparator
    {
        $driver = $this->createDatabaseDriver($connectionName);

        return new SchemaComparator($driver);
    }

    /**
     * Create a Grammar for the specified connection.
     */
    public function getGrammar(string $connectionName = 'default'): GrammarInterface
    {
        $driver = $this->getDriverName($connectionName);

        return $this->createGrammarForDriver($driver);
    }

    /**
     * Create a type normalizer for the specified connection.
     */
    protected function createTypeNormalizer(string $connectionName): TypeNormalizerInterface
    {
        $driver = $this->getDriverName($connectionName);

        return $this->createTypeNormalizerForDriver($driver);
    }

    /**
     * Create a database driver for the specified connection.
     */
    protected function createDatabaseDriver(string $connectionName): DatabaseDriver
    {
        $driver = $this->getDriverName($connectionName);
        $pdo = $this->connection($connectionName);

        return $this->createDatabaseDriverForDriver($driver, $pdo);
    }

    protected function createTypeNormalizerForDriver(string $driver): TypeNormalizerInterface
    {
        return match ($driver) {
            'mysql' => new MySqlTypeNormalizer(),
            // 'pgsql' => new PostgresTypeNormalizer(),
            // 'sqlite' => new SqliteTypeNormalizer(),
            default => throw ConnectionException::invalidConfiguration(
                $driver,
                "No type normalizer available for driver: {$driver}"
            ),
        };
    }

    protected function createDatabaseDriverForDriver(string $driver, PDO $pdo): DatabaseDriver
    {
        return match ($driver) {
            'mysql' => new MySqlDriver($pdo),
            // 'pgsql' => new PostgresDriver($pdo),
            // 'sqlite' => new SqliteDriver($pdo),
            default => throw ConnectionException::invalidConfiguration(
                $driver,
                "No database driver available for: {$driver}"
            ),
        };
    }

    protected function createGrammarForDriver(string $driver): GrammarInterface
    {
        return match ($driver) {
            'mysql' => new MySqlGrammar(),
            // 'pgsql' => new PostgresGrammar(),
            // 'sqlite' => new SqliteGrammar(),
            default => throw ConnectionException::invalidConfiguration(
                $driver,
                "No grammar available for driver: {$driver}"
            ),
        };
    }

    protected function createConnection(array $config): PDO
    {
        $this->validateConfiguration($config);

        $dsn = $this->buildDsn($config);

        return $this->establishConnection($dsn, $config);
    }

    protected function establishConnection(string $dsn, array $config): PDO
    {
        try {
            return new PDO(
                $dsn,
                $config['username'] ?? null,
                $config['password'] ?? null,
                $this->getDefaultPdoOptions()
            );
        } catch (\PDOException $e) {
            throw ConnectionException::connectionFailed(
                $config['driver'] ?? 'unknown',
                $e->getMessage()
            );
        }
    }

    protected function getDefaultPdoOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
    }

    protected function buildDsn(array $config): string
    {
        return match ($config['driver']) {
            'mysql' => $this->buildMySqlDsn($config),
            'pgsql' => $this->buildPostgresDsn($config),
            'sqlite' => $this->buildSqliteDsn($config),
            default => throw ConnectionException::invalidConfiguration(
                $config['driver'],
                "Unsupported driver: {$config['driver']}"
            ),
        };
    }

    protected function buildMySqlDsn(array $config): string
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 3306;
        $database = $config['database'];

        return "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    }

    protected function buildPostgresDsn(array $config): string
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 5432;
        $database = $config['database'];

        return "pgsql:host={$host};port={$port};dbname={$database}";
    }

    protected function buildSqliteDsn(array $config): string
    {
        return "sqlite:{$config['database']}";
    }

    protected function validateConfiguration(array $config): void
    {
        if (! isset($config['driver'])) {
            throw ConnectionException::invalidConfiguration(
                'unknown',
                'Missing required "driver" configuration'
            );
        }

        if ($this->requiresDatabase($config) && ! isset($config['database'])) {
            throw ConnectionException::invalidConfiguration(
                $config['driver'],
                'Missing required "database" configuration'
            );
        }
    }

    protected function requiresDatabase(array $config): bool
    {
        return $config['driver'] !== 'sqlite';
    }
}