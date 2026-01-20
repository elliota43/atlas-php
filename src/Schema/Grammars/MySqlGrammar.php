<?php

namespace Atlas\Schema\Grammars;

use Atlas\Changes\TableChanges;
use Atlas\Schema\Definition\ColumnDefinition;
use Atlas\Schema\Definition\TableDefinition;

class MySqlGrammar
{

    protected $sqlKeywords = [
      'NULL',
      'TRUE',
      'FALSE',
      'CURRENT_TIMESTAMP',
      'CURRENT_DATE',
      'CURRENT_TIME',
    ];

    public function createTable(TableDefinition $table): string
    {
        $lines = [];
        
        foreach ($table->columns as $column) {
            $lines[] = $this->compileColumn($column);
        }

        $primaryKeys = $this->getPrimaryKeys($table);
        if (!empty($primaryKeys)) {
            $cols = implode('`, `', $primaryKeys);
            $lines[] = "PRIMARY KEY (`{$cols}`)";
        }

        foreach ($this->compileUniqueConstraints($table) as $constraint) {
            $lines[] = $constraint;
        }

        foreach ($this->compileIndexes($table) as $index) {
            $lines[] = $index;
        }

        foreach ($this->compileForeignKeys($table) as $foreignKey) {
            $lines[] = $foreignKey;
        }

        $body = implode(",\n    ", $lines);

        return "CREATE TABLE `{$table->tableName}` (\n    {$body}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    }

    public function generateAlter(TableChanges $diff): array
    {
        $statements = [];

        foreach ($diff->removedColumns as $colName) {
            $statements[] = "ALTER TABLE `{$diff->tableName}` DROP COLUMN `{$colName}`;";
        }

        foreach ($diff->addedColumns as $column) {
            $def = $this->compileColumn($column);
            $statements[] = "ALTER TABLE `{$diff->tableName}` ADD COLUMN {$def};";
        }

        foreach ($diff->modifiedColumns as $column) {
            $def = $this->compileColumn($column);
            $statements[] = "ALTER TABLE `{$diff->tableName}` MODIFY COLUMN {$def};";
        }

        return $statements;
    }

    public function compileColumn(ColumnDefinition $col): string
    {
        $parts = ["`{$col->name()}`", $this->formatType($col->sqlType())];

        if ($col->isNullable()) {
            $parts[] = 'NULL';
        } else {
            $parts[] = 'NOT NULL';
        }

        if ($col->isAutoIncrement()) {
            $parts[] = 'AUTO_INCREMENT';
        }

        if ($col->defaultValue() !== null) {
            $default = $this->compileDefaultValue($col->defaultValue());
            $parts[] = "DEFAULT {$default}";
        }

        if ($col->onUpdate !== null) {
            $parts[] = "ON UPDATE {$col->onUpdate}";
        }

        return implode(' ', $parts);
    }

    /**
     * Format the SQL type preserving case and structure.
     */
    protected function formatType(string $type): string
    {
        // Handle compound types (e.g., "bigint unsigned", "enum('a','b')", "decimal(10,2)")
        if (preg_match('/^(\w+)(.*)$/i', $type, $matches)) {
            $baseType = strtoupper($matches[1]);
            $rest = $matches[2]; // Keep case-sensitive parts like enum values
            
            // Uppercase the UNSIGNED keyword if present
            $rest = str_ireplace(' unsigned', ' UNSIGNED', $rest);
            
            return $baseType . $rest;
        }
        
        return strtoupper($type);
    }

    private function getPrimaryKeys(TableDefinition $table): array
    {
        if ($table->compositePrimaryKey !== null) {
            return $table->compositePrimaryKey;
        }
        
        $pks = [];
        foreach ($table->columns as $col) {
            if ($col->isPrimaryKey()) {
                $pks[] = $col->name();
            }
        }

        return $pks;
    }

    /**
     * Compile unique constraints from columns.
     */
    protected function compileUniqueConstraints(TableDefinition $table): array
    {
        $constraints = [];

        foreach ($table->columns as $column) {
            if ($column->isUnique() && !$column->isPrimaryKey()) {
                $constraints[] = "UNIQUE KEY `{$column->name()}_unique` (`{$column->name()}`)";
            }
        }

        return $constraints;
    }

    /**
     * Compile indexes from table definition.
     */
    protected function compileIndexes(TableDefinition $table): array
    {
        $indexes = [];

        foreach ($table->indexes as $index) {
            $name = $index['name'] ?? $this->generateIndexName($table->tableName, $index['columns']);
            $columns = implode('`, `', $index['columns']);
            $type = $index['type'] ? " USING {$index['type']}" : '';
            
            if ($index['unique']) {
                $indexes[] = "UNIQUE KEY `{$name}` (`{$columns}`){$type}";
            } else {
                $indexes[] = "KEY `{$name}` (`{$columns}`){$type}";
            }
        }

        return $indexes;
    }

    /**
     * Compile foreign key constraints.
     */
    protected function compileForeignKeys(TableDefinition $table): array
    {
        $constraints = [];

        foreach ($table->columns as $column) {
            foreach ($column->foreignKeys() as $fk) {
                $name = $fk['name'] ?? $this->generateForeignKeyName($table->tableName, $column->name());
                
                [$refTable, $refColumn] = $this->parseReference($fk['references']);
                
                $constraint = "CONSTRAINT `{$name}` FOREIGN KEY (`{$column->name()}`) REFERENCES `{$refTable}` (`{$refColumn}`)";
                
                if ($fk['onDelete']) {
                    $constraint .= " ON DELETE {$fk['onDelete']}";
                }
                
                if ($fk['onUpdate']) {
                    $constraint .= " ON UPDATE {$fk['onUpdate']}";
                }
                
                $constraints[] = $constraint;
            }
        }

        return $constraints;
    }

    /**
     * Generate automatic index name.
     */
    protected function generateIndexName(string $table, array $columns): string
    {
        return $table . '_' . implode('_', $columns) . '_index';
    }

    /**
     * Generate automatic foreign key constraint name.
     */
    protected function generateForeignKeyName(string $table, string $column): string
    {
        return $table . '_' . $column . '_foreign';
    }

    /**
     * Parse foreign key reference string.
     */
    protected function parseReference(string $reference): array
    {
        if (str_contains($reference, '.')) {
            return explode('.', $reference, 2);
        }

        throw new \InvalidArgumentException("Foreign key reference must be in format 'table.column'");
    }

    /**
     * Compiles a default value for SQL output.
     * Handles NULL, Keywords, functions, and string literals.
     *
     * @param mixed $value
     * @return string
     */
    protected function compileDefaultValue(mixed $value): string
    {
        // handle php null
        if ($value === null) {
            return 'NULL';
        }

        // Handle numbers
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_string($value)) {
            $upper = strtoupper(trim($value));

            // SQL keyword
            if (in_array($upper, $this->sqlKeywords, true)) {
                return $upper;
            }

            // function call
            if (str_contains($value, '(')) {
                return $value;
            }

            // escape if its a string literal
            $escaped = str_replace("'", "''", $value);
            return "'{$escaped}'";
        }

        return (string) $value;
    }
}