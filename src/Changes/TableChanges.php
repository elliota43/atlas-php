<?php

namespace Atlas\Changes;

use Atlas\Analysis\DestructivenessLevel;
use Atlas\Schema\Definition\ColumnDefinition;

class TableChanges
{
    /** @var ColumnDefinition[] */
    public array $addedColumns = [];

    /** @var string[] */
    public array $removedColumns = [];

    /** @var ColumnDefinition[] */
    public array $modifiedColumns = [];

    /**
     * @var array<string, DestructivenessLevel>
     */
    public array $modificationDestructiveness = [];

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

    /**
     * Checks if there are any destructive changes.
     * @return bool
     */
    public function hasDestructiveChanges(): bool
    {
        if (!empty($this->removedColumns)) {
            return true;
        }

        foreach ($this->modificationDestructiveness as $level) {
            if ($level->isDestructive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines if there are any potentially destructive changes.
     * @return bool
     */
    public function hasPotentiallyDestructiveChanges(): bool
    {
        if (in_array(DestructivenessLevel::POTENTIALLY_DESTRUCTIVE, $this->modificationDestructiveness, true)) {
            return true;
        }
        return false;
    }

    /**
     * Adds modified column with destructiveness level to the TableChanges
     *
     * @param ColumnDefinition $column
     * @param DestructivenessLevel $level
     * @return void
     */
    public function addModifiedColumn(ColumnDefinition $column, DestructivenessLevel $level): void
    {
        $this->modifiedColumns[] = $column;
        $this->modificationDestructiveness[$column->name()] = $level;
    }

    public function changeCount(): int
    {
        return count($this->addedColumns)
            + count($this->removedColumns)
            + count($this->modifiedColumns);
    }
}