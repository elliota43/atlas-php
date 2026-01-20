<?php

namespace SchemaOps\Schema\Definition;

class TableDefinition {

    /**
     * @param string $tableName
     * @param array $columns = []
     */
    public function __construct(
        public string $tableName,
        public array $columns = []
    ) {}

    public function addColumn(ColumnDefinition $column): void
    {
        $this->columns[$column->name()] = $column;
    }

    public function hasColumn(string $name): bool
    {
        return isset($this->columns[$name]);
    }

    public function getColumn(string $name): ?ColumnDefinition
    {
        return $this->columns[$name] ?? null;
    }

    public function columnNames(): array
    {
        return array_keys($this->columns);
    }

    public function primaryKeys(): array
    {
        return array_filter(
            $this->columns,
            fn ($col) => $col->isPrimaryKey()
        );
    }

    public function tableName(): string
    {
        return $this->tableName;
    }

    public function columns(): array
    {
        return $this->columns;
    }
}