<?php

namespace SchemaOps\Comparison;

use SchemaOps\Attributes\Table;
use SchemaOps\Changes\TableChanges;
use SchemaOps\Schema\Definition\TableDefinition;
use SchemaOps\Schema\Definition\ColumnDefinition;

class TableComparator
{
    public function compare(TableDefinition $current, TableDefinition $desired): TableChanges
    {
        $diff = new TableChanges($desired->tableName);

        $this->detectAddedColumns($current, $desired, $diff);
        $this->detectModifiedColumns($current, $desired, $diff);
        $this->detectRemovedColumns($current, $desired, $diff);

        return $diff;
    }

    /**
     * Detect columns that exist in desired but not in current,
     * indicating a new column.
     * @param TableDefinition $current
     * @param TableDefinition $desired
     * @param TableChanges $diff
     * @return void
     */
    protected function detectAddedColumns(
        TableDefinition $current,
        TableDefinition $desired,
        TableChanges $diff
    ): void {
        foreach ($desired->columns as $name => $column ) {
            if (! $current->hasColumn($name)) {
                $diff->addedColumns[] = $column;
            }
        }
    }

    /**
     * Detect columns that have been modified.
     *
     * @param TableDefinition $current
     * @param TableDefinition $desired
     * @param TableChanges $diff
     * @return void
     */
    protected function detectModifiedColumns(
        TableDefinition $current,
        TableDefinition $desired,
        TableChanges $diff
    ): void {
        foreach ($desired->columns as $name => $desiredColumn) {
            if (! $current->hasColumn($name)) {
                continue;
            }

            $comparator = new ColumnComparator(
                $current->getColumn($name),
                $desiredColumn
            );

            if ($comparator->hasChanges()) {
                $diff->modifiedColumns[] = $desiredColumn;
            }
        }
    }

    /**
     * Detects columns that exist in current but not in desired.
     *
     * @param TableDefinition $current
     * @param TableDefinition $desired
     * @param TableChanges $diff
     * @return void
     */
    protected function detectRemovedColumns(
        TableDefinition $current,
        TableDefinition $desired,
        TableChanges $diff
    ): void {
        foreach ($current->columns as $name => $column) {
            if (! $desired->hasColumn($name)) {
                $diff->removedColumns[] = $name;
            }
        }
    }
}