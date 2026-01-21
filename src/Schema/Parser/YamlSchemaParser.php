<?php

namespace Atlas\Schema\Parser;

use Atlas\Database\TypeNormalizerInterface;
use Atlas\Exceptions\SchemaException;
use Atlas\Schema\Definition\ColumnDefinition;
use Atlas\Schema\Definition\TableDefinition;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
final class YamlSchemaParser
{
    public function __construct(
        private TypeNormalizerInterface $normalizer,
    ) {}

    /**
     * Parses a YAML file.
     *
     * @param string $path
     * @return array
     */
    public function parseFile(string $path): array
    {
        if (! is_file($path)) {
            throw SchemaException::invalidYamlFile($path);
        }

        $yaml = file_get_contents($path);

        if ($yaml === false) {
            throw SchemaException::unreadableFile($path);
        }

        return $this->parseString($yaml, $path);
    }

    /**
     * Parses multiple YAML schema files.
     * 
     * @param array<string> $paths Array of file paths.
     * @return array<TableDefinition>
     * @throws SchemaException If duplicate tables are found
     */
    public function parseFiles(array $paths): array
    {
        $allDefinitions = [];
        $tableLocations = [];

        foreach ($paths as $path) {
            $definitions = $this->parseFile($path);

            foreach ($definitions as $tableName => $definition) {
                if (isset($tableLocations[$tableName])) {
                    throw SchemaException::duplicateTableDefinition(
                        $tableName,
                        $tableLocations[$tableName],
                        $path
                    );
                }

                $tableLocations[$tableName] = $path;
                $allDefinitions[] = $definition;
            }
        }

        return $allDefinitions;
    }

    /**
     * Parse a YAML schema string and return table definitions.
     *
     * @param string $yaml the YAML content to parse
     * @param string $sourceLabel A label for error messages (e.g., filename))
     * @return array<string, TableDefinition>
     * @throws SchemaException if the YAML is invalid or malformed.
     */
    public function parseString(string $yaml, string $sourceLabel = '<yaml>'): array
    {
        $data = $this->parseYaml($yaml, $sourceLabel);

        $this->ensureValidRootStructure($data, $sourceLabel);

        return $this->parseTables($data['tables'], $sourceLabel);
    }

    /**
     * Parse the YAML string into an array.
     * @param string $yaml The YAML content to parse.
     * @param string $sourceLabel A label for error messages.
     * @return array The parsed YAML data.
     * @throws SchemaException if the YAML is invalid or not an array
     */
    private function parseYaml(string $yaml, string $sourceLabel): array
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw SchemaException::invalidYamlContent($sourceLabel, $e->getMessage());
        }

        if (!is_array($data)) {
            throw SchemaException::yamlRootMustBeMap($sourceLabel);
        }

        return $data;
    }

    /**
     * Ensure the root YAML structure contains a valid 'tables' key
     *
     * @param array $data The parsed YAML data.
     * @param string $sourceLabel A label for error messages
     * @return void
     * @throws SchemaException If the 'tables' key is missing or invalid.
     */
    private function ensureValidRootStructure(array $data, string $sourceLabel): void
    {
        if (! isset($data['tables']) || ! is_array($data['tables'])) {
            throw SchemaException::missingTablesKey($sourceLabel);
        }
    }

    /**
     * Parse all table definitions from the YAML data.
     *
     * @param array $tables The tables array from YAML.
     * @param string $sourceLabel A label for error messages
     * @return array<string, TableDefinition> Table definitions keyed by table name
     * @throws SchemaException If any table definition is invalid
     */
    private function parseTables(array $tables, string $sourceLabel): array
    {
        $definitions = [];

        foreach ($tables as $tableName => $tableData) {
            $this->ensureValidTableName($tableName, $sourceLabel);
            $this->ensureValidTableData($tableName, $tableData, $sourceLabel);

            $definitions[$tableName] = $this->buildTableDefinition($tableName, $tableData);
        }

        return $definitions;
    }

    /**
     * Ensure the table name is a non-empty string.
     *
     * @param mixed $tableName The table name to validate.
     * @param string $sourceLabel A label for error messages
     * @return void
     * @throws SchemaException If the table name is invalid
     */
    private function ensureValidTableName(mixed $tableName, string $sourceLabel): void
    {
        if (! is_string($tableName) || $tableName === '') {
            throw SchemaException::invalidTableName($sourceLabel);
        }
    }

    /**
     * Ensure the table data is a valid array.
     *
     * @param string $tableName The table name being validated.
     * @param mixed $tableData The table data to validate.
     * @param string $sourceLabel A label for error messages
     * @return void
     * @throws SchemaException If the table data is invalid
     */
    private function ensureValidTableData(string $tableName, mixed $tableData, string $sourceLabel): void
    {
        if (! is_array($tableData)) {
            throw SchemaException::tableMustBeMap($tableName, $sourceLabel);
        }
    }

    /**
     * Build a complete table definition from YAML data.
     *
     * @param string $tableName The name of the table
     * @param array $tableData The table's YAML data
     * @return TableDefinition The constructed table definition
     * @throws SchemaException if the table data is invalid
     */
    private function buildTableDefinition(string $tableName, array $tableData): TableDefinition
    {
        $definition = new TableDefinition($tableName);

        $this->setPrimaryKey($definition, $tableData, $tableName);
        $this->setColumns($definition, $tableData, $tableName);
        $this->setIndexes($definition, $tableData, $tableName);

        return $definition;
    }

    /**
     * Set the composite primary key on the table definition if present.
     *
     * @param TableDefinition $definition The table definition to modify.
     * @param array $tableData The table's YAML data
     * @param string $tableName The table name for error messages
     * @return void
     * @throws SchemaException If the primary key definition is invalid
     */
    private function setPrimaryKey(TableDefinition $definition, array $tableData, string $tableName): void
    {
        if (! array_key_exists('primaryKey', $tableData)) {
            return;
        }

        $primaryKey = $tableData['primaryKey'];

        if ($primaryKey !== null && ! is_array($primaryKey)) {
            throw SchemaException::primaryKeyMustBeList($tableName);
        }

        $definition->compositePrimaryKey = $primaryKey;
    }

    /**
     * Parse and add all column definitions to the table.
     *
     * @param TableDefinition $definition The table definition to modify
     * @param array $tableData The table's YAML data
     * @param string $tableName The table name for error messages
     * @return void
     * @throws SchemaException If any column definition is invalid
     */
    private function setColumns(TableDefinition $definition, array $tableData, string $tableName): void
    {
        if (! isset($tableData['columns']) || ! is_array($tableData['columns'])) {
            throw SchemaException::columnsMustBeMap($tableName);
        }

        foreach ($tableData['columns'] as $columnName => $columnData) {
            $this->ensureValidColumnName($tableName, $columnName);
            $this->ensureValidColumnData($tableName, $columnName, $columnData);

            $definition->addColumn(
                $this->buildColumnDefinition($tableName, $columnName, $columnData)
            );
        }
    }

    /**
     * Ensures the column name is a non-empty string.
     *
     * @param  string  $tableName  The table name for error messages
     * @param  mixed  $columnName  The column name to validate
     * @return void
     * @throws SchemaException If the column name is invalid
     */
    private function ensureValidColumnName(string $tableName, mixed $columnName): void
    {
        if (! is_string($columnName) || $columnName === '') {
            throw SchemaException::columnNameMustBeString($tableName);
        }
    }

    /**
     * Ensure the column data is a valid array.
     *
     * @param  string  $tableName  The table name for error messages
     * @param  string  $columnName  The column name for error messages
     * @param  mixed  $columnData  The column data to validate
     * @return void
     * @throws SchemaException If the column data is invalid
     */
    private function ensureValidColumnData(string $tableName, string $columnName, mixed $columnData): void
    {
        if (! is_array($columnData)) {
            throw SchemaException::columnMustBeMap($tableName, $columnName);
        }
    }

    /**
     * Parse and add all index definitions to the table.
     *
     * @param  TableDefinition  $definition  The table definition to modify
     * @param  array  $tableData  The table's YAML data
     * @param  string  $tableName  The table name for error messages
     * @return void
     * @throws SchemaException If any index definition is invalid
     */
    private function setIndexes(TableDefinition $definition, array $tableData, string $tableName): void
    {
        if (! isset($tableData['indexes'])) {
            return;
        }

        if (! is_array($tableData['indexes'])) {
            throw SchemaException::indexesMustBeList($tableName);
        }

        foreach ($tableData['indexes'] as $i => $index) {
            if (! is_array($index)) {
                throw SchemaException::indexMustBeMap($tableName, $i);
            }

            $definition->addIndex($this->normalizeIndex($tableName, $index, $i));
        }
    }

    /**
     * Build a column definition from YAML data.
     *
     * @param  string  $tableName  The table name for error messages
     * @param  string  $columnName  The column name
     * @param  array  $columnData  The column's YAML data
     * @return ColumnDefinition The constructed column definition
     * @throws SchemaException If the column data is invalid
     */
    private function buildColumnDefinition(string $tableName, string $columnName, array $columnData): ColumnDefinition
    {
        $type = $columnData['type'] ?? null;

        if (! is_string($type) || trim($type) === '') {
            throw SchemaException::columnTypeMissing($tableName, $columnName);
        }

        return new ColumnDefinition(
            name: $columnName,
            sqlType: $this->normalizer->normalize($this->buildSqlType($type, $columnData)),
            isNullable: (bool) ($columnData['nullable'] ?? false),
            isAutoIncrement: (bool) $this->getColumnOption($columnData, 'auto_increment', 'autoIncrement'),
            isPrimaryKey: (bool) $this->getColumnOption($columnData, 'primary', 'primaryKey'),
            defaultValue: $columnData['default'] ?? null,
            onUpdate: $this->getColumnOption($columnData, 'on_update', 'onUpdate'),
            isUnique: (bool) ($columnData['unique'] ?? false),
            foreignKeys: $this->parseForeignKeys($tableName, $columnName, $columnData),
        );
    }

    /**
     * Get a column option, supporting both snake_case and camelCase keys.
     *
     * @param  array  $columnData  The column's YAML data
     * @param  string  $snakeKey  The snake_case key
     * @param  string  $camelKey  The camelCase key
     * @return mixed The value or null if not found
     */
    private function getColumnOption(array $columnData, string $snakeKey, string $camelKey): mixed
    {
        return $columnData[$snakeKey] ?? $columnData[$camelKey] ?? null;
    }

    /**
     * Parse foreign key definitions for a column.
     *
     * Supports both singular `foreign_key` format and plural `foreignKeys` array format.
     *
     * @param  string  $tableName  The table name for error messages
     * @param  string  $columnName  The column name for error messages
     * @param  array  $columnData  The column's YAML data
     * @return array The normalized foreign key definitions
     * @throws SchemaException If any foreign key definition is invalid
     */
    private function parseForeignKeys(string $tableName, string $columnName, array $columnData): array
    {
        // Support singular foreign_key format (more intuitive for YAML)
        if (isset($columnData['foreign_key'])) {
            return [$this->normalizeSingularForeignKey($tableName, $columnName, $columnData['foreign_key'])];
        }

        // Support plural foreignKeys array format
        if (! isset($columnData['foreignKeys'])) {
            return [];
        }

        if (! is_array($columnData['foreignKeys'])) {
            throw SchemaException::foreignKeysMustBeList($tableName, $columnName);
        }

        $foreignKeys = [];

        foreach ($columnData['foreignKeys'] as $i => $foreignKey) {
            if (! is_array($foreignKey)) {
                throw SchemaException::foreignKeyMustBeMap($tableName, $columnName, $i);
            }

            $foreignKeys[] = $this->normalizeForeignKey($tableName, $columnName, $foreignKey, $i);
        }

        return $foreignKeys;
    }

    /**
     * Normalize a singular foreign_key definition (table/column format).
     *
     * @param  string  $tableName  The table name for error messages
     * @param  string  $columnName  The column name for error messages
     * @param  array  $foreignKey  The foreign key data
     * @return array The normalized foreign key definition
     */
    private function normalizeSingularForeignKey(string $tableName, string $columnName, array $foreignKey): array
    {
        $refTable = $foreignKey['table'] ?? null;
        $refColumn = $foreignKey['column'] ?? 'id';

        if (! is_string($refTable) || trim($refTable) === '') {
            throw SchemaException::foreignKeyReferencesMissing($tableName, $columnName, 0);
        }

        return [
            'references' => "{$refTable}.{$refColumn}",
            'onDelete' => $foreignKey['on_delete'] ?? $foreignKey['onDelete'] ?? null,
            'onUpdate' => $foreignKey['on_update'] ?? $foreignKey['onUpdate'] ?? null,
            'name' => $foreignKey['name'] ?? null,
        ];
    }

    /**
     * Build a SQL type string from the column data.
     *
     * Applies length, precision/scale, and unsigned modifiers as needed.
     *
     * @param  string  $type  The base type (e.g., 'varchar', 'int', 'decimal')
     * @param  array  $columnData  The column's YAML data
     * @return string The complete SQL type string
     * @throws SchemaException If type parameters are invalid
     */
    private function buildSqlType(string $type, array $columnData): string
    {
        $type = strtolower(trim($type));

        $sql = $this->applyLength($type, $columnData);
        $sql = $this->applyPrecision($sql, $type, $columnData);
        $sql = $this->applyUnsigned($sql, $type, $columnData);

        return $sql;
    }

    /**
     * Apply length modifier to string types (varchar, char).
     *
     * @param  string  $type  The base type
     * @param  array  $columnData  The column's YAML data
     * @return string The type with length applied if applicable
     * @throws SchemaException If the length is not an integer
     */
    private function applyLength(string $type, array $columnData): string
    {
        if (! isset($columnData['length'])) {
            return $type;
        }

        if (! is_int($columnData['length'])) {
            throw SchemaException::lengthMustBeInteger();
        }

        if (! in_array($type, ['varchar', 'char'])) {
            return $type;
        }

        return "{$type}({$columnData['length']})";
    }

    /**
     * Apply precision and scale modifiers to decimal types.
     *
     * @param  string  $sql  The current SQL type string
     * @param  string  $type  The base type
     * @param  array  $columnData  The column's YAML data
     * @return string The type with precision/scale applied if applicable
     * @throws SchemaException If precision or scale are not integers
     */
    private function applyPrecision(string $sql, string $type, array $columnData): string
    {
        if ($type !== 'decimal' || ! isset($columnData['precision'])) {
            return $sql;
        }

        if (! is_int($columnData['precision'])) {
            throw SchemaException::precisionMustBeInteger();
        }

        if (! array_key_exists('scale', $columnData)) {
            return "decimal({$columnData['precision']})";
        }

        if (! is_int($columnData['scale'])) {
            throw SchemaException::scaleMustBeInteger();
        }

        return "decimal({$columnData['precision']}, {$columnData['scale']})";
    }

    /**
     * Apply unsigned modifier to numeric types.
     *
     * @param  string  $sql  The current SQL type string
     * @param  string  $type  The base type
     * @param  array  $columnData  The column's YAML data
     * @return string The type with unsigned modifier applied if applicable
     */
    private function applyUnsigned(string $sql, string $type, array $columnData): string
    {
        $unsigned = (bool) ($columnData['unsigned'] ?? false);

        if (! $unsigned || str_contains($sql, ' unsigned')) {
            return $sql;
        }

        $numericTypes = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'float', 'double'];

        if (! in_array($type, $numericTypes)) {
            return $sql;
        }

        return $sql . ' unsigned';
    }

    /**
     * Normalize a foreign key definition into a standard array format.
     *
     * @param  string  $tableName  The table name for error messages
     * @param  string  $columnName  The column name for error messages
     * @param  array  $foreignKey  The foreign key YAML data
     * @param  int  $i  The index of this foreign key in the list
     * @return array The normalized foreign key definition
     * @throws SchemaException If the foreign key definition is invalid
     */
    private function normalizeForeignKey(string $tableName, string $columnName, array $foreignKey, int $i): array
    {
        $references = $foreignKey['references'] ?? null;

        if (! is_string($references) || trim($references) === '') {
            throw SchemaException::foreignKeyReferencesMissing($tableName, $columnName, $i);
        }

        return [
            'references' => $references,
            'onDelete' => $foreignKey['onDelete'] ?? null,
            'onUpdate' => $foreignKey['onUpdate'] ?? null,
            'name' => $foreignKey['name'] ?? null,
        ];
    }

    /**
     * Normalize an index definition into a standard array format.
     *
     * @param  string  $tableName  The table name for error messages
     * @param  array  $index  The index YAML data
     * @param  int  $i  The index of this index in the list
     * @return array The normalized index definition
     * @throws SchemaException If the index definition is invalid
     */
    private function normalizeIndex(string $tableName, array $index, int $i): array
    {
        $columns = $index['columns'] ?? null;

        if (! is_array($columns) || $columns === []) {
            throw SchemaException::indexColumnsMissing($tableName, $i);
        }

        foreach ($columns as $column) {
            if (! is_string($column) || $column === '') {
                throw SchemaException::indexColumnsMustBeStrings($tableName, $i);
            }
        }

        return [
            'columns' => array_values($columns),
            'name' => $index['name'] ?? null,
            'unique' => (bool) ($index['unique'] ?? false),
            'type' => $index['type'] ?? null,
            'length' => $index['length'] ?? null,
        ];
    }
}