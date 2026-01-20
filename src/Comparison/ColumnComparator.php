<?php

namespace SchemaOps\Comparison;

use SchemaOps\Schema\Definition\ColumnDefinition;

class ColumnComparator
{
    public function __construct(
        protected ColumnDefinition $current,
        protected ColumnDefinition $desired
    )
    {
    }

    /**
     * Check if there are any changes between the columns.
     */
    public function hasChanges(): bool
    {
        return $this->hasTypeChanged()
            || $this->hasNullabilityChanged()
            || $this->hasAutoIncrementChanged()
            || $this->hasDefaultChanged();
    }

    /**
     * Check if the column type has changed.
     */
    public function hasTypeChanged(): bool
    {
        return $this->current->sqlType() !== $this->desired->sqlType();
    }

    /**
     * Check if the nullability has changed.
     */
    public function hasNullabilityChanged(): bool
    {
        return $this->current->isNullable() !== $this->desired->isNullable();
    }

    /**
     * Check if auto-increment has changed.
     */
    public function hasAutoIncrementChanged(): bool
    {
        return $this->current->isAutoIncrement() !== $this->desired->isAutoIncrement();
    }

    /**
     * Check if the default value has changed
     */
    public function hasDefaultChanged(): bool
    {
        return $this->current->defaultValue() !== $this->desired->defaultValue();
    }

    /**
     * Get a human-readable list of changes.
     */

    public function getChanges(): array
    {
        $changes = [];

        if ($this->hasTypeChanged()) {
            $changes[] = [
                'property' => 'type',
                'from' => $this->current->sqlType(),
                'to' => $this->desired->sqlType()
            ];
        }

        if ($this->hasNullabilityChanged()) {
            $changes[] = [
                'property' => 'nullable',
                'from' => $this->current->isNullable(),
                'to' => $this->desired->isNullable(),
            ];
        }

        if ($this->hasAutoIncrementChanged()) {
            $changes[] = [
                'property' => 'auto_increment',
                'from' => $this->current->isAutoIncrement(),
                'to' => $this->desired->isAutoIncrement(),
            ];
        }

        if ($this->hasDefaultChanged()) {
            $changes[] = [
                'property' => 'default',
                'from' => $this->current->defaultValue(),
                'to' => $this->desired->defaultValue(),
            ];
        }

        return $changes;
    }

    /**
     * Get a summary string of all changes.
     */
    public function getSummary(): string
    {
        $parts = [];

        foreach ($this->getChanges() as $change) {
            $from = $this->formatValue($change['from']);
            $to = $this->formatValue($change['to']);
            $parts[] = "{$change['property']}: {$from} → {$to}";
        }

        return implode(', ', $parts);
    }

    /**
     * Format a value for display.
     */
    protected function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'YES' : 'NO';
        }

        if (is_null($value)) {
            return 'NULL';
        }

        return (string) $value;
    }
}