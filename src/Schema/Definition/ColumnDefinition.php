<?php

namespace SchemaOps\Schema\Definition;

class ColumnDefinition
{
    public function __construct(
        public string $name,
        public string $sqlType,
        public bool $isNullable,
        public bool $isAutoIncrement,
        public bool $isPrimaryKey,
        public mixed $defaultValue,
        public ?string $onUpdate = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function sqlType(): string
    {
        return $this->sqlType;
    }

    public function isNullable(): bool
    {
        return $this->isNullable;
    }

    public function isAutoIncrement(): bool
    {
        return $this->isAutoIncrement;
    }

    public function isPrimaryKey(): bool
    {
        return $this->isPrimaryKey;
    }

    public function defaultValue(): mixed
    {
        return $this->defaultValue;
    }
}