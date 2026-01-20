<?php

namespace SchemaOps\Changes;

use SchemaOps\Schema\Definition\ColumnDefinition;

class TableChanges
{
    /** @var ColumnDefinition[] */
    public array $addedColumns = [];

    /** @var string[] */
    public array $removedColumns = [];

    /** @var ColumnDefinition[] */
    public array $modifiedColumns = [];

    public function __construct(public string $tableName) {}

    public function isEmpty(): bool
    {
        return empty($this->addedColumns)
            && empty($this->removedColumns)
            && empty($this->modifiedColumns);
    }

    /**
     * Check if there are any changes.
     *
     * @return bool
     */
    public function hasChanges(): bool
    {
        return ! $this->isEmpty();
    }

    public function hasDestructiveChanges(): bool
    {
        return ! empty($this->removedColumns);
    }

    public function changeCount(): int
    {
        return count($this->addedColumns)
            + count($this->removedColumns)
            + count($this->modifiedColumns);
    }
}