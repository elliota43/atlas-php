<?php

namespace SchemaOps\State;

use SchemaOps\Schema\Definition\ColumnDefinition;
use SchemaOps\Schema\Definition\TableDefinition;

class LockfileManager
{
    private string $filePath;

    public function __construct(string $projectPath)
    {
        $this->filePath = $projectPath . '/schema.lock';
    }

    public function exists(): bool
    {
        return file_exists($this->filePath);
    }

    public function load(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $data = json_decode(file_get_contents($this->filePath), true);

        return $this->hydrate($data['tables']);
    }

    /**
     * @param TableDefinition[] $definitions
     */
    public function save(array $definitions): void
    {
        $data = [
            '_meta' => [
                'generated_at' => date('c'),
                'hash' => $this->calculateHash($definitions)
            ],
            'tables' => $this->dehydrate($definitions)
        ];

        file_put_contents(
            $this->filePath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Convert Objects -> Arrays for JSON storage
     * @param TableDefinition[] $definitions
     * @return array
     */
    private function dehydrate(array $definitions): array
    {
        $output = [];
        foreach ($definitions as $def) {
            $cols = [];
            foreach ($def->columns as $col) {
                $cols[] = [
                    'name' => $col->name(),
                    'type' => $col->sqlType(),
                    'nullable' => $col->isNullable(),
                    'isAutoIncrement' => $col->isAutoIncrement(),
                    'isPrimaryKey' => $col->isPrimaryKey(),
                    'defaultValue' => $col->defaultValue()
                ];
            }

            $output[$def->tableName] = ['columns' => $cols];
        }

        return $output;
    }

    /**
     * Convert JSON array -> TableDefinition Objects
     * @param array $jsonData
     * @return TableDefinition[]
     */
    private function hydrate(array $jsonData): array
    {
        $definitions = [];

        foreach ($jsonData as $tableName => $tableData) {
            $table = new TableDefinition($tableName);

            foreach ($tableData['columns'] as $colData) {
                $table->addColumn(new ColumnDefinition(
                    name: $colData['name'],
                    sqlType: $colData['type'],
                    isNullable: $colData['nullable'],
                    isAutoIncrement: $colData['isAutoIncrement'] ?? false,
                    isPrimaryKey: $colData['isPrimaryKey'] ?? false,
                    defaultValue: $colData['defaultValue'] ?? null
                ));
            }

            $definitions[$tableName] = $table;
        }

        return $definitions;
    }

    /**
     * Calculate hash for schema integrity checking
     * @param TableDefinition[] $definitions
     * @return string
     */
    private function calculateHash(array $definitions): string
    {
        return hash('sha256', json_encode($this->dehydrate($definitions)));
    }
}