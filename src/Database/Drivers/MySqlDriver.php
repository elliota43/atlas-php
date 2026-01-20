<?php

namespace SchemaOps\Database\Drivers;

use PDO;
use SchemaOps\Schema\Definition\ColumnDefinition;
use SchemaOps\Schema\Definition\TableDefinition;

class MySqlDriver implements DriverInterface
{
    public function __construct(private PDO $pdo) {}

    public function getCurrentSchema(): array
    {
        $tables = [];

        $stmt = $this->pdo->query("
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE()
        ");

        $tableNames = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tableNames as $tableName) {
            $tables[$tableName] = new TableDefinition(
                $tableName,
                $this->getColumns($tableName)
            );
        }

        return $tables;
    }

    private function getColumns(string $tableName): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                COLUMN_NAME,
                COLUMN_TYPE,
                IS_NULLABLE,
                COLUMN_KEY,
                COLUMN_DEFAULT,
                EXTRA
            FROM information_schema.columns
            WHERE table_name = :table
            AND table_schema = DATABASE()
        ");

        $stmt->execute(['table' => $tableName]);
        $rawColumns = $stmt->fetchall(PDO::FETCH_ASSOC);

        $definitions = [];

        foreach ($rawColumns as $row) {
            $name = $row['COLUMN_NAME'];

            $isNullable = $row['IS_NULLABLE'] === 'YES';

            $isAutoIncrement = str_contains($row['EXTRA'], 'auto_increment');

            $isPrimaryKey = $row['COLUMN_KEY'] === 'PRI';

            $definitions[$name] = new ColumnDefinition(
                name: $name,
                sqlType: $row['COLUMN_TYPE'],
                isNullable: $isNullable,
                isAutoIncrement: $isAutoIncrement,
                isPrimaryKey: $isPrimaryKey,
                defaultValue: $row['COLUMN_DEFAULT']
            );
        }
        return $definitions;
    }
}